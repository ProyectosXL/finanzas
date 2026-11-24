---
titulo: Logística Local
pestana: logistica_local
categoria: Proveedores
estado: completa
actualizado: 2026-11-24
usuarios: Tesorería, Administración
bajada: Cuánto se le paga a cada fletero y en qué fecha: horas por mes por el valor hora del mes, en los pagos del cronograma, o los pagos puntuales de los no habituales.
---

## Para qué sirve

Muestra cuánto se le va a pagar a cada fletero, y cuándo, en todo el horizonte del cashflow.

A un fletero **habitual** se le calcula: las horas que trabaja por mes por el valor hora de ese mes, repartido en partes iguales en los pagos del cronograma. A uno **no habitual** no se le calcula nada: se proyectan sólo los pagos puntuales que se le cargan a mano.

Todo es proyección: no hay parte real. Lo que muestra entra al tablero en la fila Logística, de la sección Costos Directos. Ver [cómo se lee el tablero](ayuda:general_tablero).

## De dónde salen los datos

:::diagrama
fuente: Parámetros › Logística | los fleteros: horas por mes, valor hora base, mes base, habitual y activo
fuente: Tango | el nombre de cada fletero, del maestro de proveedores
fuente: Parámetros › Generales | la inflación mensual, para el ajuste trimestral del valor hora
fuente: Parámetros › Calendario | el cronograma de Logística Local y los feriados
fuente: Esta pestaña | los pagos puntuales de los no habituales y el importe real del próximo pago
pestana: Logística Local | calcula cuánto se paga a cada fletero y en qué fecha
tablero: Fila Logística | todo proyectado, de mañana en adelante
alt: Parámetros › Logística, Tango, Parámetros › Generales, Parámetros › Calendario y lo cargado en la pestaña alimentan Logística Local, que pasa sus pagos a la fila Logística del tablero.
:::

- **Parámetros › Logística**: la lista de fleteros. Es una lista propia del cashflow, no un rubro de Tango. Cada uno tiene sus horas por mes, su valor hora base y su mes base, y las marcas de habitual y activo. Las horas, el valor hora y el mes base también se corrigen desde esta pestaña.
- **Tango**: el nombre de cada fletero, del maestro de proveedores. Si Tango no responde, se ve el nombre guardado al darlo de alta.
- **Parámetros › Generales**: la inflación mensual esperada, con la que se ajusta el valor hora cada tres meses. Ver [la inflación](ayuda:gastos_locales#la-inflacion).
- **Parámetros › Calendario**: el cronograma de pagos del concepto Logística Local (día de la semana, frecuencia y fechas movidas a mano) y los feriados. Ver [el cronograma de pagos](ayuda:gastos_locales#el-cronograma-de-pagos).
- **Lo que se carga en esta pestaña**: los pagos puntuales de cada no habitual y el importe real del próximo pago de un habitual.

**No se lee ninguna factura ni ningún comprobante.** Lo que se le debe a un fletero en Tango no entra acá: se calcula.

Sólo entran los fleteros **activos**. Uno dado de baja no se ve ni proyecta.

## Cómo se calcula

### El importe de un habitual

:::formula
Importe del mes = Horas por mes × Valor hora de ese mes
Cada pago = Importe del mes ÷ cantidad de pagos del mes en el cronograma
:::

El importe es final: sin IVA ni otros conceptos. No se redondea en el medio.

Con el cronograma por defecto (quincenal) son siempre dos pagos por mes, mitad y mitad, aunque el mes tenga cinco viernes. Si alguien pasa Logística Local a semanal, el importe del mes se reparte en cuatro o cinco partes.

### El valor hora mes a mes

- **El valor hora base ya está ajustado**: rige en su mes base y en los dos meses siguientes.
- **Cada tres meses desde el mes base hay un ajuste**: mes base + 3, + 6, + 9…
- **El % de cada ajuste es la suma sin componer** de la inflación del mes del ajuste y de los dos anteriores. Tres meses de 2 % dan 6 %, no 6,12 %.
- **Cada ajuste se aplica sobre el valor del trimestre anterior**, no sobre el valor base.
- **El valor hora siempre sale de esta cuenta**: no se puede corregir a mano el valor de un mes. Lo que se carga es el valor base y la inflación.

:::ejemplo ilustrativo
Un fletero habitual con **160 horas por mes**, valor hora base **$ 5.000** y mes base **septiembre**. La inflación cargada es 2 % todos los meses. El cronograma es el de siempre: el 2do y el 4to viernes.

| Meses | Valor hora | Importe del mes | Cada pago |
| --- | ---: | ---: | ---: |
| Septiembre, octubre, noviembre | $ 5.000 | 160 × $ 5.000 = $ 800.000 | $ 400.000 |
| Diciembre, enero, febrero | $ 5.000 × 1,06 = $ 5.300 | 160 × $ 5.300 = $ 848.000 | $ 424.000 |
| Marzo, abril, mayo | $ 5.300 × 1,06 = $ 5.618 | 160 × $ 5.618 = $ 898.880 | $ 449.440 |

El ajuste de diciembre suma la inflación de octubre, noviembre y diciembre: 2 + 2 + 2 = 6 %. El de marzo, la de enero, febrero y marzo, aplicada sobre los $ 5.300 de diciembre.
:::

### Cuándo se paga

Las fechas salen del cronograma de pagos del concepto **Logística Local**, en Parámetros › Calendario. Cómo funciona el cronograma está en [el cronograma de pagos](ayuda:gastos_locales#el-cronograma-de-pagos). Lo propio de Logística:

- **Por defecto se paga el 2do y el 4to viernes de cada mes** (quincenal). Si ese viernes no es hábil, el pago se corre al día hábil **anterior**.
- **El cronograma es uno solo para todos los fleteros habituales.** Mover la fecha de un pago en Parámetros › Calendario la mueve para todos.
- **Una fecha movida a mano se respeta tal cual**: no se corre al día hábil. Y ninguna factura la pisa, porque esta pestaña no lee facturas. Sólo se pierde si alguien la vuelve a la calculada o si se cambia el día o la frecuencia de Logística Local.
- **Los no habituales no usan el cronograma**: cada pago puntual va en la fecha que se le cargó, sin correrse al día hábil.

### Lo que no se proyecta

- **Un pago con fecha de hoy o anterior.** Ya se hizo y ya está en el saldo bancario que abre el tablero. Por eso la columna de hoy nunca recibe nada de esta pestaña. No se avisa: no es plata de menos, es plata que ya salió.
- **Un mes al que le falta un dato.** Sin horas, sin valor hora base, sin mes base, antes del mes base o sin la inflación que necesita un ajuste, ese mes no se proyecta. **No se pone en cero**: la celda muestra un guión y el motivo al pasar el mouse, y un aviso dice qué falta.
- **Si falta la inflación de un mes**, no se puede calcular ese ajuste ni ninguno de los siguientes: desde ahí, todos los meses quedan sin proyectar.
- **Los pagos puntuales de un fletero habitual.** Quedan guardados, pero no proyectan.

### Habituales y no habituales

- **Habitual**: se proyecta con horas × valor hora, en los pagos del cronograma. Es lo normal, y **todo fletero nace habitual** al darlo de alta.
- **No habitual**: no se calcula. Proyecta sólo sus pagos puntuales, cada uno con su fecha y su importe. Sus horas, su valor hora y su mes base se conservan, en gris, para cuando vuelva a ser habitual. No se le piden ni se avisa que le faltan.

Al volver a habitual, sus pagos puntuales futuros dejan de proyectarse. No se borran: si vuelve a ser no habitual, los que todavía no pasaron vuelven a proyectar.

### El importe real del próximo pago

El **próximo pago** de un habitual es el primero con fecha posterior a hoy. Su importe se puede reemplazar por el real. Vale sólo para ese pago: los siguientes se siguen calculando.

El importe cargado queda pegado a ese pago (su mes y su número de pago), así que si alguien mueve la fecha en Parámetros › Calendario lo sigue. Se da de baja si se cambia el día o la frecuencia de Logística Local, desde el mes en curso.

Cuando la fecha de ese pago llega, deja de proyectarse como cualquier otro, y el próximo pasa a ser el siguiente, con su importe calculado.

### Dónde cae cada pago en el eje

Cada pago va a la columna de su día si cae en el tramo diario, o a la de su mes si cae después. Por eso un mes partido por el final del tramo diario lleva un pago en su columna del día y el otro en la columna del mes: esa columna mensual muestra medio importe, y no es un error. Para las vistas, ver [las tres vistas](ayuda:general_tablero#las-tres-vistas).

## La pantalla

La pantalla, de arriba hacia abajo:

- Los avisos de la pestaña: los de la planilla y los que el tablero dice de ella.
- Las tres tarjetas.
- La planilla **Pagos proyectados por fletero**, con el buscador, los botones de las tres vistas, **Columnas fijas**, **Actualizar** y **Exportar**.
- La línea del período y del cronograma.
- La grilla, con el pie **TOTALES** y una nota al pie.
- La tabla **Valor hora mes a mes**, con su botón **Exportar**.

### Las tarjetas

| Tarjeta | Qué muestra |
| --- | --- |
| **Proyectado** | Todo lo que se proyecta en el horizonte. Abajo dice "Sin IVA ni otros conceptos" y el período. |
| **Fleteros activos** | Cuántos fleteros activos hay, habituales y no habituales. |
| **Sin proyectar** | Cuántos habituales no proyectan ningún pago porque les falta un dato. Un no habitual sin pagos puntuales no cuenta: no le falta nada. |

Las tarjetas no cambian con el buscador.

### La planilla

Debajo del título dice la regla: horas por mes × valor hora del mes, en partes iguales, y cuándo se paga. El ícono de información lo explica completo al pasar el mouse.

La línea de arriba de la grilla dice qué período se mide y, por ejemplo: "Se paga **el 2do y el 4to viernes** de cada mes; dentro del tramo diario caen N fecha(s). Las fechas se ven y se editan en Parámetros › Calendario."

Las columnas:

| Columna | Qué muestra |
| --- | --- |
| **Fletero** | El nombre (se abre con un clic: ver *El detalle de un fletero*), el código, el ícono de quién lo modificó por última vez y el interruptor **Habitual**. |
| **Horas/mes** | Las horas que trabaja por mes. |
| **Valor hora base** | El valor hora ya ajustado, que rige desde el mes base. |
| **Mes base** | Desde qué mes rige el valor hora base. Fija el calendario de los ajustes. |
| Una por día o por mes | Lo que se le paga en esa fecha. Los sábados, domingos y feriados van marcados. Arriba de cada fecha va el total de la columna. |
| **Total** | La suma de las columnas de la vista que estás mirando. |

En las columnas del eje, cada celda muestra el importe que se le paga ese día o ese mes. Un **guión** quiere decir que no hay pago ahí, o que el mes no se pudo calcular: en ese caso, el motivo está al pasar el mouse. Nunca hay un cero por falta de dato.

- **El próximo pago** de cada habitual lleva un lápiz. Al pasar el mouse dice la fecha y la cuenta, por ejemplo: "Próximo pago, 12/12/2026. Calculado: $ 424.000,00 = 160 h × $ 5.300,00 / 2 pagos. Hacé clic para cargar el importe real."
- **Un próximo pago con importe real cargado** se ve con fondo ámbar. Al pasar el mouse dice el calculado, el editado, quién lo cargó y cuándo.
- **Un no habitual** lleva las horas, el valor hora y el mes base en gris (se conservan, pero no se usan) y, debajo de su fila, la fila **Pagos puntuales:** con cada pago (fecha · importe). Los que ya pasaron van en gris con la marca **historial**; los futuros se pueden corregir o dar de baja. Al final, el lugar para agregar uno nuevo. Sin pagos dice "Sin pagos puntuales cargados: no proyecta nada."
- **El buscador** filtra por nombre o código de fletero. El pie **TOTALES** y los totales de arriba de cada fecha suman sólo lo que se ve.
- **Columnas fijas** elige qué columnas quedan quietas al moverte a lo ancho. Arranca con **Fletero**.
- **Exportar** baja a Excel lo que se está viendo.
- La pestaña abre en la vista **Días**.

La nota al pie repite las cuatro reglas que más se preguntan: el mes partido por el tramo diario, que un pago de hoy o anterior no se proyecta, que el próximo pago se puede cargar con su importe real y que un no habitual proyecta sólo sus pagos puntuales.

### El detalle de un fletero

Con un clic en el nombre se abre **Pagos del fletero**: todos sus pagos del horizonte, también los que no se proyectan.

| Columna | Qué muestra |
| --- | --- |
| **Mes** | El mes del pago. |
| **Pago** | El número de pago dentro del mes: 1 o 2 con el cronograma quincenal. En un pago puntual va vacío. |
| **Fecha** | La fecha del pago. |
| **Valor hora** | El valor hora de ese mes. Un guión si no se pudo calcular o en un pago puntual. |
| **Importe** | Lo que se paga, o un guión si no se pudo calcular. |
| **Estado** | Las marcas de abajo. |

Las marcas de **Estado**:

- **columna del día** o **columna del mes**: dónde lo pone la grilla. En los pagos puntuales dice siempre **columna del mes**, aunque caigan en el tramo diario: la grilla sí los pone en su día.
- **no se proyecta**: ya pasó, es hoy, o es un mes al que le falta un dato. El motivo está al pasar el mouse.
- **pago puntual**: de un no habitual.
- **importe a mano**: el próximo pago con su importe real. Al pasar el mouse, el calculado.
- **fecha a mano**: la fecha se movió en Parámetros › Calendario.
- **corrida**: el día de pago no era hábil y se corrió al hábil anterior.

Abajo dice "Total proyectado: $ X" o "No se proyecta nada de este fletero.", y **Exportar** baja la tabla.

### Valor hora mes a mes

Una fila por fletero y una columna por mes del horizonte, con el valor hora que rige cada mes. El mes de un ajuste va marcado con una flecha hacia arriba.

Al pasar el mouse por una celda se ve de dónde sale, por ejemplo: "Es el valor base, sin ajustar: rige desde 2026-09 y por los dos meses siguientes." o "Rige desde el ajuste de 2026-12, de 6,00 % = 2026-10: 2,00 % + 2026-11: 2,00 % + 2026-12: 2,00 % (suma sin componer), aplicado sobre el valor del trimestre anterior."

Un guión es un mes sin valor: antes del mes base, sin valor base, sin inflación para el ajuste, o un fletero no habitual. El motivo también está al pasar el mouse.

### Sin permiso de edición

Todo se ve igual, pero las horas, el valor hora y el mes base son texto, no hay interruptor **Habitual** (un no habitual lleva la marca **no habitual**), el próximo pago no tiene lápiz y no se pueden cargar ni tocar pagos puntuales. Ver [permisos y parámetros](ayuda:general_permisos_parametros).

## Tareas frecuentes

### Dar de alta un fletero

:::pasos
1. Andá a **Parámetros › Logística** y tocá **Agregar fletero**.
2. En **Proveedor de Tango**, escribí el código o el nombre (desde 2 letras) y elegilo de la lista. Hasta que no lo elegís, el botón de confirmar queda apagado.
3. Cargá **Horas por mes**, **Valor hora base** y **Mes base**. Son opcionales, pero sin los tres no se proyecta.
4. Tocá el botón de confirmar (el tilde).
Resultado: el fletero aparece en esta pestaña como **habitual**. Si le falta algo, el mensaje lo dice ("Todavía no se proyecta: le falta…") y la pestaña lo avisa, sin contarlo como cero.
:::

El **mes base** no es la fecha de alta: es el mes del último ajuste pactado. Si el proveedor ya estaba cargado, guardar corrige sus datos; si estaba dado de baja, lo reactiva.

Para que su pago no se cuente dos veces, el fletero tiene que estar **excluido de Cuentas a Pagar Locales**, desde el maestro de Proveedores Locales.

### Dar de alta un fletero no habitual

:::pasos
1. Dalo de alta como cualquier fletero (la tarea de arriba). No hace falta cargar horas, valor hora ni mes base.
2. Apagá su interruptor **Habitual**, en esta pestaña o en la columna **Habitual** de Parámetros › Logística.
3. Confirmá **Pasar a no habitual**.
Resultado: deja de proyectarse por cálculo y aparece la fila **Pagos puntuales:** debajo de su nombre.
:::

Entre el paso 1 y el 2, el fletero es habitual sin horas: por un momento va a aparecer en **Sin proyectar** y con avisos de que le faltan datos. Desaparecen al pasarlo a no habitual.

### Corregir las horas, el valor hora o el mes base

:::pasos
1. En la fila del fletero, cambiá **Horas/mes**, **Valor hora base** o **Mes base**.
2. Salí del campo: se guarda solo.
Resultado: el mensaje dice "Datos guardados: …" y la planilla se recalcula.
:::

Tiene que ser un número mayor a 0: un cero significaría que no se le paga, y eso se expresa dando de baja al fletero. Las horas no pueden pasar de 744 (las de un mes de 31 días). Vaciar un campo lo borra, y el fletero deja de proyectarse.

### Cargar un pago puntual a un no habitual

:::pasos
1. En la fila **Pagos puntuales:** del fletero, elegí la fecha. Tiene que ser de **mañana en adelante**.
2. Escribí el **Importe**.
3. Tocá **Agregar**.
Resultado: el mensaje dice "Pago puntual cargado: $ X el dd/mm/aaaa." y el importe entra en su columna.
:::

**No se puede cargar un pago para hoy**: lo que se paga hoy ya está en el saldo bancario que abre el tablero. Si el pago es mañana, cargalo hoy. La fecha no se corre al día hábil: va la que cargaste.

### Corregir o dar de baja un pago puntual

:::pasos
1. En la fila **Pagos puntuales:**, buscá el pago (sólo los futuros se pueden tocar).
2. Para corregirlo, cambiá la fecha o el importe y tocá el tilde (**Guardar la corrección**).
3. Para darlo de baja, tocá el tacho (**Dar de baja**) y confirmá.
Resultado: "Pago puntual corregido: $ X el dd/mm/aaaa." o "Pago puntual dado de baja ($ X del dd/mm/aaaa). No se borra: queda en el historial."
:::

Un pago que ya pasó queda como **historial**: no se corrige ni se da de baja.

### Cargar el importe real del próximo pago

:::pasos
1. Hacé clic en la celda del próximo pago, la que tiene el lápiz.
2. En **Importe**, escribí el importe real. Acepta `61.234,56` o `61234.56`.
3. Tocá **Guardar**.
Resultado: la celda se marca en ámbar y el mensaje dice "Importe del pago del dd/mm/aaaa: $ Y (el cálculo daba $ X). Los pagos siguientes se siguen calculando."
:::

Sólo se edita el próximo pago, no los siguientes. Se puede cargar aunque el cálculo dé guión.

### Volver al importe calculado del próximo pago

:::pasos
1. Hacé clic en la celda ámbar del próximo pago.
2. Borrá el **Importe** y dejá el campo vacío.
3. Tocá **Guardar**.
Resultado: el mensaje dice "El pago vuelve al importe calculado. El editado queda en el historial." y la celda vuelve a la cuenta.
:::

No hay otro botón para deshacerlo: vaciar el campo es la forma.

### Pasar un no habitual a habitual

:::pasos
1. Prendé su interruptor **Habitual**.
2. Leé la confirmación: dice cuántos pagos puntuales futuros dejan de proyectarse y por cuánto.
3. Confirmá **Volver a habitual**.
Resultado: se vuelve a proyectar con sus horas, su valor hora y su mes base. Si le falta alguno, el mensaje y los avisos lo dicen.
:::

### Dar de baja un fletero

:::pasos
1. Andá a **Parámetros › Logística**.
2. Apagá el interruptor **Activo** del fletero.
Resultado: "Fletero dado de baja: …. No se borra: conserva las horas y el valor hora con los que se proyectó, y se puede reactivar." Deja de verse en esta pestaña.
:::

Para reactivarlo, prendé otra vez **Activo**.

### Mover la fecha de un pago

Se hace en **Parámetros › Calendario**, en **Próximos pagos**, con un motivo obligatorio. Mueve ese pago para **todos** los fleteros habituales. Ver [el cronograma de pagos](ayuda:gastos_locales#el-cronograma-de-pagos).

## Parámetros

| Parámetro | Dónde se cambia | Valor por defecto | Qué cambia en la proyección |
| --- | --- | --- | --- |
| **Horas por mes** | Parámetros › Logística o la planilla | Vacío | El importe del mes. Vacío = no se proyecta. Mayor a 0 y hasta 744 |
| **Valor hora base** | Parámetros › Logística o la planilla | Vacío | El valor hora del mes base y los dos siguientes, y la base de los ajustes. Vacío = no se proyecta |
| **Mes base** | Parámetros › Logística o la planilla | Vacío | Desde qué mes rige el valor base y cuándo toca cada ajuste. Vacío = no se proyecta |
| **Habitual** | Parámetros › Logística o la planilla | Prendido al dar de alta | Apagado: no se calcula y proyecta sólo los pagos puntuales |
| **Activo** | Parámetros › Logística | Prendido | Apagado: el fletero no se ve ni proyecta |
| Inflación mensual esperada | Parámetros › Generales | — | El % de cada ajuste trimestral. Ver [la inflación](ayuda:gastos_locales#la-inflacion) |
| Logística Local: **Día de la semana** y **Frecuencia** | Parámetros › Calendario | Viernes, quincenal (el 2do y el 4to) | Las fechas de pago y en cuántas partes se reparte el mes. Cambiarlo da de baja las fechas movidas a mano y los importes reales del próximo pago desde el mes en curso; antes de guardar se dice cuáles |
| **Próximos pagos** (fecha a mano y motivo) | Parámetros › Calendario | La fecha calculada | La fecha de ese pago para todos los fleteros habituales |
| Columnas diarias, columnas mensuales y feriados | Parámetros › Calendario | — | Las columnas del eje y qué día es hábil |

Para quién puede editar, ver [permisos y parámetros](ayuda:general_permisos_parametros).

## Avisos y qué hacer

Los avisos se ven arriba de la planilla, con los que el tablero dice de esta pestaña. Ver [cómo se leen los avisos](ayuda:general_avisos).

:::aviso critico
texto: Estos fleteros NO están excluidos de Cuentas a Pagar Locales: COD1, COD2. Mientras no lo estén, su pago se cuenta DOS VECES en el tablero: una proyectada acá y otra por su deuda real. La exclusión se carga desde el maestro de Proveedores Locales.
significa: Esos fleteros están proyectados acá y, además, su deuda real en Tango suma en Cuentas a Pagar Locales. El tablero cuenta el mismo pago dos veces.
corrige: Excluí a cada fletero de Cuentas a Pagar Locales desde el maestro de Proveedores Locales.
:::

:::aviso atencion
texto: Nombre (código): no tiene horas por mes cargadas, así que no se proyecta ningún mes. Cargalas en la planilla o en Parámetros › Logística.
significa: Un habitual sin horas. No suma nada y cuenta en **Sin proyectar**.
corrige: Cargá **Horas/mes**. Si es un fletero no habitual, apagale **Habitual**.
:::

:::aviso atencion
texto: Nombre (código): no tiene valor hora base cargado, así que no se proyecta ningún mes.
significa: Sin valor hora no hay importe.
corrige: Cargá **Valor hora base**, en la planilla o en Parámetros › Logística.
:::

:::aviso atencion
texto: Nombre (código): no tiene mes base cargado. Sin él no se sabe desde cuándo rige el valor hora ni cuándo toca cada ajuste trimestral, así que no se proyecta ningún mes.
significa: Sin mes base no hay calendario de ajustes.
corrige: Cargá **Mes base**: el mes del último ajuste pactado.
:::

:::aviso atencion
texto: Nombre (código): falta la inflación de aaaa-mm, aaaa-mm, así que no se puede calcular un ajuste trimestral y los meses desde ahí quedan sin proyectar. Cargala en Parámetros › Generales.
significa: Un ajuste necesita la inflación de esos meses. Desde ese ajuste en adelante, el fletero no proyecta.
corrige: Cargá la inflación de esos meses en Parámetros › Generales.
:::

:::aviso atencion
texto: Nombre (código): N mes(es) del horizonte son anteriores a su mes base (aaaa-mm), así que no tienen ningún valor hora pactado y no se proyectan.
significa: El mes base es posterior al comienzo del horizonte: esos meses no tienen valor hora.
corrige: Si el valor ya regía antes, corregí el **Mes base**.
:::

:::aviso atencion
texto: No hay ningún fletero activo cargado, así que la fila Logística del tablero va en cero. Se dan de alta en Parámetros › Logística, buscando el código de proveedor en Tango.
significa: La lista de fleteros está vacía o están todos dados de baja.
corrige: Dalos de alta, o reactivalos, en Parámetros › Logística.
:::

:::aviso atencion
texto: El cronograma de Logística Local tiene un valor que no se entiende (…). Se usa el valor por defecto, el 2do y el 4to viernes. Corregilo en Parámetros › Calendario.
significa: El día o la frecuencia guardados no son válidos. Mientras tanto se paga el 2do y el 4to viernes.
corrige: Elegí de nuevo el día y la frecuencia de Logística Local en Parámetros › Calendario.
:::

:::aviso info
texto: Hay pagos proyectados por $ X con fecha posterior al final del horizonte, así que no entran en ninguna columna. Se ven igual en la pestaña Logística Local.
significa: Algún pago cae después de la última columna del eje. Es del tablero.
corrige: Nada. Si los querés ver en el tablero, subí las columnas mensuales en Parámetros › Calendario.
:::

:::aviso atencion
texto: La fila va en CERO porque no hay ningún fletero activo cargado. Se dan de alta en Parámetros › Logística.
significa: Es del tablero: la fila Logística está en cero porque no hay fleteros.
corrige: Dalos de alta en Parámetros › Logística.
:::

:::aviso atencion
texto: Hay N fletero(s) activos pero la fila va en CERO: a ninguno se le pudo calcular un importe. Los otros avisos de esta pestaña dicen qué le falta a cada uno. NO significa que no haya que pagarles.
significa: Es del tablero. Hay fleteros, pero ninguno proyecta nada.
corrige: Mirá los avisos de cada fletero y cargá lo que falta.
:::

### Los mensajes al guardar

No son avisos: aparecen un momento después de cada acción.

| Mensaje | Cuándo |
| --- | --- |
| "Datos guardados: …." | Se guardaron las horas, el valor hora o el mes base. |
| "Todavía no se proyecta: le falta las horas por mes, el valor hora base, el mes base. No se cuenta como cero." | Va pegado al anterior, con lo que le falta a un habitual. |
| "Pasa a no habitual: deja de proyectarse por cálculo. …" / "Vuelve a ser habitual: …" | Se cambió el interruptor **Habitual**. |
| "Tiene que ser un número mayor a 0. Un cero significaría que no se le paga, …" | Se escribió un cero o algo que no es número en las horas o el valor hora. No se guarda. |
| "No se pudo guardar: …" | Falló el guardado de un dato. El campo vuelve al último valor guardado. |
| "La fecha tiene que ser de mañana en adelante: lo que se paga hoy ya está reflejado en el saldo bancario." | Un pago puntual con fecha de hoy o anterior. No se guarda. |
| "El importe tiene que ser mayor a 0." | Un pago puntual sin importe o en cero. |
| "Pago puntual cargado: $ X el dd/mm/aaaa." / "Pago puntual corregido: …" | Se guardó un pago puntual. |
| "Pago puntual dado de baja ($ X del dd/mm/aaaa). No se borra: queda en el historial." | Se dio de baja un pago puntual. |
| "No se pudo guardar el pago: …" | Falló. Por ejemplo, "Ese pago ya pasó y quedó en el historial: no se corrige." |
| "Importe del pago del dd/mm/aaaa: $ Y (el cálculo daba $ X). Los pagos siguientes se siguen calculando." | Se cargó el importe real del próximo pago. |
| "El pago vuelve al importe calculado. El editado queda en el historial." | Se vació el importe del próximo pago. |
| "No se pudo guardar el importe: …" | Falló. Por ejemplo, "Sólo se puede editar el importe del próximo pago, que es el del aaaa-mm-dd. Los siguientes se calculan.", si el próximo pago cambió mientras tenías la pantalla abierta. |
| "Fletero dado de alta: … (…)." / "Fletero reactivado: …." | En Parámetros › Logística. |
| "No se pudo guardar el fletero: …" | Falló el alta. Por ejemplo, el código ya no está en Tango o Tango no responde. |

Los mensajes de error no se cierran solos: el texto del servidor es lo que explica qué pasó.

### Avisos para Sistemas

Los de instalación y los de lectura: falta una tabla, falta correr un script o no se pudo leer algo. No los corrige quien usa la pantalla: hay que avisar a sistemas, con el texto del aviso.

:::aviso critico
texto: Todavía no existe RO_T_CASHFLOW_LOGISTICA_FLETEROS: corré sql/cashflow_logistica_fleteros.sql contra la base central. Mientras tanto no se puede dar de alta ningún fletero y la fila Logística del tablero va en cero.
significa: La lista de fleteros no está instalada. No se proyecta nada.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso critico
texto: La fila va en CERO porque todavía no existe el maestro de fleteros. Un egreso en cero no significa que no haya que pagar nada.
significa: Es del tablero, por el mismo motivo que el aviso de arriba.
corrige: Sistemas, instalando la lista de fleteros.
:::

:::aviso atencion
texto: Todavía no se corrió sql/cashflow_logistica_no_habituales.sql contra la base central. Mientras tanto todos los fleteros son habituales y se proyectan como siempre, pero no se puede marcar a ninguno como no habitual, cargar pagos puntuales ni editar el importe del próximo pago.
significa: Falta instalar los no habituales. Todo se calcula, pero no hay interruptor **Habitual**, pagos puntuales ni lápiz en el próximo pago.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso critico
texto: No se pudieron leer los pagos puntuales ni los importes editados (…). Los fleteros no habituales no proyectan nada y los próximos pagos van con el importe calculado.
significa: Falló la lectura. Lo que se proyecta puede estar mal.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso critico
texto: Todavía no existe RO_T_CASHFLOW_INFLACION_MES: corré sql/cashflow_parametros_generales.sql contra la base central. Mientras tanto no se puede cargar la inflación, y los valores hora de Logística Local se proyectan sin ajuste trimestral.
significa: Falta instalar la inflación.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso critico
texto: No se pudo leer la inflación mensual (…). Los valores hora se proyectan sin ajuste trimestral.
significa: Falló la lectura de la inflación.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: Falta configurar el cronograma de Logística Local: corré sql/cashflow_cronograma_conceptos.sql contra la base central. Mientras tanto se paga el 2do y el 4to viernes, que es el valor por defecto.
significa: El cronograma no está instalado. Se usa el de siempre.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso critico
texto: No se pudieron leer los pagos movidos a mano del cronograma de Logística Local (…). Se usan las fechas calculadas.
significa: Las fechas movidas en Parámetros › Calendario no se están aplicando.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: Todavía no existe RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT: corré sql/cashflow_parametros_generales.sql contra la base central. Mientras tanto las fechas del cronograma se calculan y se ven, pero no se pueden editar.
significa: No se pueden mover fechas a mano. Las calculadas valen igual.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: RO_T_CALENDARIO no tiene datos para aaaa-mm. Se asumen hábiles los días de lunes a viernes.
significa: El calendario bancario no llega a ese mes: un feriado de ese mes no corre ningún pago.
corrige: Avisale a sistemas que hay que extender el calendario.
:::

:::aviso critico
texto: No se pudo leer el calendario bancario (…). Las fechas se calculan asumiendo hábiles los días de lunes a viernes, así que un feriado no corre ninguna fecha.
significa: Falló la lectura del calendario.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso critico
texto: Todavía no se puede excluir un proveedor de ningún módulo: falta la tabla …. Corré … contra la base central. Mientras tanto no hay ninguno excluido y todo se proyecta como hasta ahora. Hasta que exista no se puede saber si los fleteros están excluidos de Cuentas a Pagar Locales, así que su pago podría estar contándose dos veces en el tablero.
significa: Falta instalar la exclusión de proveedores. El pago de los fleteros puede estar contándose dos veces.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso critico
texto: No se pudo verificar si los fleteros están excluidos de Cuentas a Pagar Locales (…). Si no lo están, su pago se cuenta dos veces en el tablero.
significa: Falló la lectura de las exclusiones.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso critico
texto: No se pudo calcular la proyección de logística: …
significa: La pestaña no pudo cargar. Después de los dos puntos va el error.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

:::aviso critico
texto: No se pudo calcular (…). Sus filas se muestran en cero.
significa: Es del tablero: la fila Logística no se pudo calcular y va en cero.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: No se pudieron traer los avisos del tablero para esta pestaña.
significa: Se ven los avisos de la pestaña, pero no los que el tablero dice de ella.
corrige: Probá con **Actualizar**. Si sigue, avisá a sistemas.
:::

En Parámetros › Logística, además del aviso de la lista de fleteros sin instalar y el de los no habituales:

:::aviso atencion
texto: No se puede leer CPA01, así que no se pueden dar de alta fleteros ni refrescar sus nombres. Los que ya están cargados se ven con el nombre guardado.
significa: Tango no responde. **Agregar fletero** queda apagado.
corrige: Probá más tarde. Si sigue, avisá a sistemas.
:::

:::aviso critico
texto: No se pudieron leer los fleteros: …
significa: La sección no pudo cargar.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

## Preguntas frecuentes

:::pregunta ¿Se pierde la fecha que moví en Parámetros › Calendario cuando llega la factura del fletero?
No. Esta pestaña no lee facturas: la fecha que cargaste se respeta tal cual, sin correrla al día hábil. Sólo se pierde si alguien la vuelve a la calculada, o si se cambia el día o la frecuencia de Logística Local (y antes de guardar se avisa).

Tené en cuenta que la fecha movida vale para todos los fleteros habituales, y no toca a los no habituales, que van en la fecha de cada pago puntual.

Si la factura del fletero aparece en Cuentas a Pagar Locales, es que ese fletero no está excluido allá, y el pago se está contando dos veces: el aviso crítico de arriba lo dice.
:::

:::pregunta ¿Puedo cargar un pago puntual para hoy?
No. Lo que se paga hoy se considera ya reflejado en el saldo bancario que abre el tablero, así que la fecha tiene que ser de mañana en adelante. Si el pago es mañana, cargalo hoy.

Lo mismo con el próximo pago de un habitual: su importe se puede cambiar hasta el día anterior. El día del pago deja de proyectarse, y el próximo pasa a ser el siguiente.
:::

:::pregunta ¿Cómo vuelvo al importe calculado del próximo pago?
Hacé clic en la celda ámbar, dejá el **Importe** vacío y tocá **Guardar**. El pago vuelve a la cuenta y el importe que se había cargado queda en el historial.
:::

:::pregunta ¿Por qué un fletero no habitual recién dado de alta figura con datos que le faltan?
Porque todo fletero nace habitual, y un habitual sin horas, valor hora ni mes base no se proyecta: por eso los avisos y la tarjeta **Sin proyectar**. Apagale el interruptor **Habitual** y desaparecen. Desde ahí se le cargan pagos puntuales.
:::

:::pregunta ¿Por qué un fletero tiene guiones en la planilla?
Un guión es un mes sin pago o que no se pudo calcular. Pasá el mouse: el motivo está ahí. Puede ser que le falten las horas, el valor hora base o el mes base, que el mes sea anterior a su mes base, o que falte la inflación de algún mes del ajuste. Los avisos de arriba dicen qué cargar.

En un no habitual, los meses sin pagos puntuales van con guión. Y en el mes en curso, los pagos que ya pasaron no se proyectan.
:::

:::pregunta ¿Por qué la columna de hoy está vacía?
Porque un pago con fecha de hoy ya se hizo y está en el saldo bancario que abre el tablero. Proyectarlo sería contar dos veces la misma plata.
:::

:::pregunta ¿Por qué una columna mensual muestra medio importe?
Porque el mes cae partido por el final del tramo diario: un pago queda adentro, en su columna del día, y el otro afuera, en la columna del mes. Sumando las dos da el mes completo.
:::

:::pregunta ¿Por qué cambió el valor hora este mes?
Es un mes de ajuste: va marcado con una flecha en **Valor hora mes a mes**. Pasá el mouse para ver el % y de qué meses de inflación sale. El ajuste es la suma sin componer de la inflación del mes y de los dos anteriores, aplicada sobre el valor del trimestre anterior.
:::

:::pregunta ¿Puedo corregir el valor hora de un mes?
No. El valor de cada mes sale siempre de la cuenta. Si cambió lo pactado, cargá el nuevo **Valor hora base** con su **Mes base**. Si cambió la inflación, se carga en Parámetros › Generales.
:::

:::pregunta ¿Qué pasa con los pagos puntuales si vuelvo un fletero a habitual?
Los futuros dejan de proyectarse: la confirmación dice cuántos y por cuánto. No se borran, pero mientras sea habitual no se ven en la planilla. Si vuelve a ser no habitual, los que todavía no pasaron vuelven a proyectar.
:::

:::pregunta ¿La pestaña y el tablero dicen lo mismo?
Sí: la fila Logística suma los mismos pagos, ubicados con la misma regla. Todo es proyectado. Más sobre real y proyectado en [real y proyectado](ayuda:general_real_proyectado).
:::

## Glosario

| Término | Qué quiere decir |
| --- | --- |
| Fletero | Un proveedor de logística al que se le paga por hora. Se da de alta en Parámetros › Logística |
| Habitual | Fletero que se proyecta con horas por mes × valor hora, en los pagos del cronograma |
| No habitual | Fletero que no se calcula: proyecta sólo sus pagos puntuales |
| Pago puntual | Un pago a un no habitual, con su fecha y su importe, cargado a mano |
| Horas por mes | Las horas que trabaja el fletero en un mes. Régimen constante |
| Valor hora base | El valor hora ya ajustado que rige en el mes base y los dos siguientes |
| Mes base | Desde qué mes rige el valor hora base. Fija el calendario de los ajustes |
| Ajuste trimestral | Cada tres meses desde el mes base, el valor hora sube la suma sin componer de la inflación de tres meses |
| Próximo pago | El primer pago de un habitual con fecha posterior a hoy. El único cuyo importe se puede cambiar |
| Importe a mano | El importe real cargado para el próximo pago, en lugar del calculado |
| Fecha a mano | Una fecha de pago movida en Parámetros › Calendario. No se corre al día hábil |
| Corrida | Una fecha de pago que caía en un día no hábil y pasó al hábil anterior |
| Historial | Lo que ya pasó o se dio de baja: se ve, pero no proyecta |
| Sin proyectar | Habitual al que le falta un dato y no proyecta ningún pago |
| Tramo diario | Las columnas de días de la vista Días |
| Horizonte | El período que proyecta el cashflow: el tramo diario más los meses |
