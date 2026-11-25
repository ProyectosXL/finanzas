---
titulo: Préstamos
pestana: prestamos
categoria: Financiero
estado: en_revision
actualizado: 2026-11-25
usuarios: Finanzas, Dirección
bajada: Los préstamos bancarios en pesos o en dólares: su cronograma, cuánto suman en el cashflow, la cancelación anticipada y cuánto haría falta pedir.
---

## Para qué sirve

Sirve para tres cosas:

- **Registrar los préstamos bancarios**, en pesos o en dólares, y proyectar sus cuotas en el cashflow.
- **Simular préstamos** que todavía no se pidieron, sin tocar el tablero oficial.
- **Decir cuánto haría falta pedir y para cuándo**, cuando el saldo del cashflow queda en negativo después de usar la cobertura.

Lo que muestra entra al tablero en la sección **Financiamiento**, entre Egresos y Resultados: desembolsos, amortización de capital e intereses y gastos.

No cubre el descubierto en cuenta corriente, los préstamos de socios, la prefinanciación de importaciones, el leasing, el descuento de cheques ni los préstamos ajustados por UVA.

## De dónde salen los datos

:::diagrama
fuente: Lo que cargás en esta pestaña | cada préstamo con sus condiciones, las correcciones de cuotas y las cancelaciones
fuente: Parámetros › Préstamos | los costos, la comisión de cancelación y los valores por defecto, que cada préstamo nuevo copia
fuente: El BCRA | TAMAR y BADLAR, para los préstamos a tasa variable
fuente: La curva del dólar futuro | el dólar de cada mes, para los préstamos en dólares
fuente: El calendario bancario | los feriados, para correr una cuota al día hábil
fuente: Saldos | las cuentas bancarias, para elegir el banco
pestana: Préstamos | calcula el cronograma de cada préstamo
tablero: Sección Financiamiento | desembolsos, capital e intereses y gastos, en real, proyectado y simulado
alt: Lo que se carga en la pestaña, Parámetros › Préstamos, las tasas del BCRA, el dólar futuro, el calendario bancario y las cuentas de Saldos alimentan la pestaña Préstamos, que pasa sus pagos a la sección Financiamiento del tablero.
:::

- **Lo que se carga acá**: los datos de cada préstamo (banco, moneda, capital, tasa, sistema, cuotas, fechas), las correcciones de cuotas para que coincidan con el cronograma del banco, y las cancelaciones anticipadas.
- **Parámetros › Préstamos**: los valores generales. Cada préstamo nuevo los copia al darse de alta, y después se cambian en el préstamo.
- **El BCRA**: las tasas TAMAR y BADLAR las trae un proceso de Sistemas todos los días a las 8. El cashflow sólo las lee.
- **La curva del dólar futuro**: pasa a pesos cada pago de un préstamo en dólares, con el dólar del mes en que se paga.
- **El calendario bancario**, con los ajustes de Parámetros › Calendario: una cuota que vence un día no hábil se paga el hábil siguiente.
- **Saldos**: el banco de un préstamo se elige entre las mismas cuentas bancarias que muestra Saldos. En esta etapa es informativo.

## Cómo se calcula

### Las fechas

Las **cuotas** son las de capital. El plazo es la cantidad de cuotas por la frecuencia de capital, y tiene que ser múltiplo de la frecuencia de intereses: con capital trimestral e intereses mensuales, 8 cuotas son 24 meses y 24 vencimientos de intereses.

La **primera cuota** es el primer vencimiento de la frecuencia más corta. Si la dejás vacía, es el desembolso más un período. Los vencimientos siguientes suman meses desde ahí; si el día no existe en el mes (un 31), va al último día del mes.

Una cuota que vence un sábado, un domingo o un feriado **se paga el día hábil siguiente**, y la grilla lo marca como **corrida**. Los días de interés se cuentan igual entre las fechas de vencimiento, no entre las corridas.

### Los intereses

El dato es siempre la **TNA**. El interés de cada cuota se calcula por días exactos, con año de 365, como liquidan los bancos:

:::formula
Interés = saldo de capital × TNA × días / 365
:::

:::ejemplo ilustrativo
Un préstamo con saldo de $ 10.000.000 y TNA de 36,5 %, en una cuota de 30 días: 10.000.000 × 0,365 × 30 / 365 = $ 300.000 de interés.
:::

### La amortización

- **Alemán**: el capital es fijo, el capital dividido las cuotas que no son de gracia.
- **Francés**: la cuota es constante. Se calcula con la tasa del período (TNA × 30, 90, 180 o 365 días / 365, según la frecuencia), el interés va por días exactos y el capital es la cuota menos el interés. Sólo se puede usar con capital e intereses en la misma frecuencia. A tasa variable, la cuota se recalcula al empezar cada período con la tasa de ese período.
- **Bullet**: todo el capital en la última cuota; los intereses, con su frecuencia.

Con **períodos de gracia**, las primeras cuotas pagan sólo intereses sobre el capital total, y el capital se reparte en las que quedan. **La última cuota cierra el capital exacto**, salvo que la hayas corregido a mano.

### Los costos

- **Iniciales**: un % del capital, el día del desembolso. En el tablero el desembolso va entero en **Desembolsos** y los costos iniciales van a **Intereses y gastos** ese mismo día.
- **Por cuota**: un % del interés de cada cuota (por ejemplo, el IVA sobre los intereses) o del saldo de capital al empezar el período.
- Si un costo lleva IVA, se le suma la alícuota de Parámetros › Generales.

### La tasa variable

La tasa de cada cuota es la **tasa base más el spread**. La tasa base sale, en este orden:

1. Si la cuota está **corregida**, la corrección.
2. Si hay un **valor cargado a mano** en Parámetros › Préstamos para el mes en que empieza el período, ese valor.
3. Si el período **ya empezó**, el promedio de las 5 publicaciones anteriores a su inicio. Si no hay tantas, el promedio más viejo que se pueda armar, con un aviso.
4. Si el período **todavía no empezó**, el promedio de las últimas 5 publicaciones, constante para todas las cuotas que vienen. Un préstamo puede pedir que se use el **último valor publicado** en lugar del promedio.

La cantidad de publicaciones del promedio (5) se cambia en Parámetros › Préstamos.

### Real, proyectado y simulado

Un pago con fecha de hoy o anterior se toma como **real**. Al tablero va sólo lo de hoy en adelante: lo anterior ya está en el saldo de los bancos. La pestaña muestra todo el cronograma.

Un préstamo **simulado** no suma en el tablero oficial. Se ve en la pestaña y, con el interruptor **Incluir simulaciones**, también en el tablero.

### En dólares

Cada pago, el desembolso incluido, se pasa a pesos con el dólar futuro del mes en que se paga. Si la curva no llega a ese mes, se usa el mes más cercano. Si no hay curva, ese pago no se pasa a pesos y no suma en el tablero, con un aviso.

### TEA y CFT

La **TEA** es la tasa efectiva anual de la TNA: (1 + TNA × d / 365) elevado a 365 / d, menos 1, con d los días del período de intereses. El **CFT** es la tasa anual que iguala el desembolso con todo lo que se paga (capital, intereses y costos). Los dos son informativos.

### La cancelación anticipada

Un préstamo vigente se puede cancelar total o parcialmente en cualquier fecha.

- **Total**: se paga el saldo de capital, los intereses devengados hasta ese día (por días exactos, no los futuros), la comisión con su IVA y los costos por cuota que van sobre esos intereses. Las cuotas siguientes dejan de proyectarse.
- **Parcial**: se paga el capital que se adelanta, los intereses devengados hasta ese día y la comisión con su IVA. Después se reduce la cuota (mismo plazo) o el plazo (misma cuota).
- **La comisión** es un % del saldo en la total y del monto adelantado en la parcial. Puede ser fija o por tramos según el % del plazo que ya pasó.

En el tablero, el capital cancelado va a **Amortización de capital**; los intereses devengados, la comisión y su IVA, a **Intereses y gastos**.

### Cuánto haría falta pedir

El simulador mira el saldo acumulado **con cobertura** del tablero oficial, sin simulaciones, y busca el primer día en negativo y el saldo más bajo del horizonte.

:::formula
Monto sugerido = necesidad × (1 + margen de seguridad) / (1 − % de costos iniciales)
:::

La **necesidad** es el saldo más bajo, en positivo. Los costos iniciales son los activos de Parámetros, con su IVA.

:::ejemplo ilustrativo
Si el saldo más bajo es −$ 10.000.000, el margen es 0 % y los costos iniciales suman 1,2 %: 10.000.000 / 0,988 = $ 10.121.457. Con ese monto, después de pagar los costos iniciales quedan los $ 10.000.000 que faltan.
:::

El monto sugerido no tiene en cuenta las cuotas del propio préstamo, que pueden generar faltantes más adelante. Para eso está la comparación con y sin.

## La pantalla

De arriba hacia abajo:

- El enlace a **Parámetros › Préstamos** y el botón **Actualizar**.
- El panel de **Avisos**, con lo que dice el tablero de esta pestaña y los avisos propios.
- Las **tarjetas**: Deuda vigente, Cuotas del horizonte, Próxima cuota, Necesidad de financiamiento y Préstamos simulados.
- Cuatro solapas: **Préstamos**, **Cronograma**, **Proyección** y **Simulador**.

### Las tarjetas

| Tarjeta | Qué dice |
| --- | --- |
| **Deuda vigente** | El saldo de capital a hoy de los préstamos vigentes, en pesos. Los de dólares, con el dólar futuro de hoy |
| **Cuotas del horizonte** | Capital, intereses y gastos de los vigentes que se pagan dentro del horizonte |
| **Próxima cuota** | La fecha y el importe del próximo pago, y de qué préstamo |
| **Necesidad de financiamiento** | Cuánto falta y para cuándo. Muestra «—» hasta que la calculás en Simulador |
| **Préstamos simulados** | Cuántos hay y cuánto capital suman. No entran en el tablero oficial |

### La solapa Préstamos

La lista, con banco, moneda, capital, tasa, sistema, cuotas, estado, saldo a hoy, próxima cuota, TEA y CFT. Arriba, el filtro por estado y **Nuevo préstamo**. En cada fila, según el estado: **Cronograma**, **Editar**, **Pasar a vigente**, **Cancelar** y **Eliminar**. Los simulados van en itálica y atenuados.

Los estados son: **Simulado**, **Vigente**, **Cancelado** (por una cancelación total anticipada ya ocurrida) y **Finalizado** (ya se pagó la última cuota).

### La solapa Cronograma

Arriba elegís el préstamo y ves sus datos, su TEA y su CFT. La tabla tiene, por cuota: número, vencimiento, fecha de pago (marcada **corrida** si se movió), días, tasa y de dónde salió (fija, promedio, último valor, manual o corregida), saldo inicial, capital, interés, cada costo, comisión, total y si es **Real** o **Proyectado**. Lo **subrayado** está corregido a mano; al pasar el mouse dice quién y cuándo.

Al pie, cómo se proyecta la tasa de un préstamo variable. Abajo, las **cancelaciones registradas**, con **Deshacer** mientras su fecha no haya pasado.

### La solapa Proyección

La grilla con el eje del cashflow: las tres vistas, columnas fijas, totales y **Exportar**. Cada préstamo se abre en desembolso, capital e intereses y gastos. El desembolso va en positivo y lo que se paga en negativo. Tiene su propio interruptor **Incluir simulaciones**.

### La solapa Simulador

**Calcular** corre el tablero oficial y muestra el primer día en negativo, el saldo mínimo, la necesidad y el monto sugerido. Abajo, la comparación del saldo del cashflow **con y sin** un préstamo simulado, mes a mes.

## Tareas frecuentes

### Cargar un préstamo

:::pasos
1. Tocá **Nuevo préstamo**.
2. Completá el nombre, el **Banco**, la moneda, el capital y la fecha de desembolso.
3. Elegí la tasa: **Fija** con su TNA, o **Variable** con la referencia (TAMAR o BADLAR) y el spread.
4. Elegí el sistema, la cantidad de cuotas y las frecuencias. Dejá vacía la primera cuota si es el desembolso más un período.
5. Revisá los costos y la comisión de cancelación: vienen de Parámetros. Cambiá sólo lo que el contrato tenga distinto.
6. Elegí el estado: **Simulado** si todavía no se pidió, **Vigente** si ya se firmó.
7. Tocá **Guardar**.
Resultado: el préstamo aparece en la lista con su cronograma. Si es vigente, suma en el tablero.
:::

### Corregir una cuota para que coincida con el banco

:::pasos
1. En **Cronograma**, elegí el préstamo.
2. Tocá **Corregir** en la cuota.
3. Escribí el capital, el interés, la TNA o el costo que dice el cronograma del banco. Lo que no cambies no se guarda.
4. Tocá **Guardar**.
Resultado: lo corregido pisa lo calculado y queda subrayado. Con **Deshacer todas las correcciones de la cuota** vuelve lo calculado.
:::

### Pasar un simulado a vigente

:::pasos
1. En la lista, tocá **Pasar a vigente** en el préstamo simulado.
2. Ajustá los datos que hayan cambiado al firmarlo.
3. Tocá **Pasar a vigente** y confirmá.
Resultado: el préstamo suma en el tablero oficial. No hace falta volver a cargarlo.
:::

### Cancelar un préstamo

:::pasos
1. En la lista, tocá **Cancelar**, o en **Cronograma** tocá **Cancelación anticipada**.
2. Elegí **Total** o **Parcial** y la fecha. En la parcial, el capital a adelantar y si se reduce la cuota o el plazo.
3. Tocá **Simular**: ves el total a pagar, los intereses que se ahorran, el costo de la comisión y cómo queda el cashflow mes a mes.
4. Si conviene, tocá **Registrar** y confirmá.
Resultado: el cronograma se rearma desde esa fecha. La cancelación se puede deshacer mientras la fecha no haya pasado.
:::

### Saber cuánto haría falta pedir

:::pasos
1. Abrí **Simulador** y tocá **Calcular**. Tarda: corre el tablero entero.
2. Leé el primer día en negativo, la necesidad y el monto sugerido.
3. Si querés probarlo, elegí el banco y tocá **Simular préstamo**.
4. En **Saldo del cashflow con y sin**, elegí el simulado y tocá **Comparar**.
Resultado: queda un préstamo simulado en la lista para ajustarlo, y ves cómo cambia el saldo mes a mes con él.
:::

### Ver el tablero con los préstamos simulados

:::pasos
1. En el tablero (pestaña **Cashflow**), prendé **Incluir simulaciones**.
Resultado: cada renglón de Financiamiento suma una parte **Simulado**, en itálica, y arriba aparece «Incluye préstamos simulados: no es el cashflow oficial». Las tarjetas, el saldo, las columnas en rojo y la cobertura se recalculan. Al volver a entrar, el interruptor arranca apagado.
:::

### Eliminar un préstamo simulado

:::pasos
1. En la lista, tocá **Eliminar** en el simulado y confirmá.
Resultado: deja de verse. Un vigente no se elimina: se cancela.
:::

## Parámetros

En **Parámetros › Préstamos** están los valores generales. **Cada préstamo nuevo los copia al darse de alta**; un cambio ahí vale para los que se den de alta después, y los que ya existen conservan sus condiciones.

- **Tasas de referencia**: la última TAMAR y BADLAR publicadas y su promedio, los días hábiles del promedio (5), la antigüedad que se tolera del último valor (5 días hábiles) y el valor cargado a mano por mes.
- **Costos asociados**: nombre, %, base (capital o intereses), momento (inicial o por cuota), si lleva IVA y si está activo. Un costo inicial no puede ir sobre los intereses.
- **Comisión de cancelación anticipada**: fija o por tramos, el % para la total y para la parcial, y si lleva IVA.
- **Simulador**: sistema, cuotas, frecuencia y TNA con que se arma un préstamo simulado, y el margen de seguridad (10 %).
- **Monedas**: pesos y dólares.

## Avisos y qué hacer

:::aviso critico
texto: Faltan las tablas de Préstamos: hay que correr sql/cashflow_prestamos.sql contra la base central. Hasta entonces no se cargan préstamos ni parámetros, y el tablero no suma nada de préstamos.
significa: Falta instalar la pestaña en la base.
corrige: Avisá a Sistemas.
:::

:::aviso critico
texto: No se pudieron leer los préstamos (…).
significa: Falló la lectura de la base. La pestaña y el tablero no suman nada de préstamos.
corrige: Tocá **Actualizar**. Si sigue, avisá a Sistemas.
:::

:::aviso critico
texto: «Nombre» no se pudo calcular (…): no suma nada. Revisá sus datos.
significa: Ese préstamo tiene un dato que no se puede calcular.
corrige: Editá el préstamo y revisá sus fechas, cuotas y frecuencias.
:::

:::aviso critico
texto: No se pudieron leer las tasas de referencia del BCRA (…). Los préstamos a tasa variable no se proyectan; los de tasa fija, sí.
significa: No se puede leer el servidor donde están las tasas.
corrige: Avisá a Sistemas. Mientras tanto, los variables no suman en el tablero.
:::

:::aviso critico
texto: No hay ningún valor publicado de TAMAR: los préstamos que la usan no se proyectan.
significa: La tabla de tasas no tiene ningún valor de esa referencia.
corrige: Avisá a Sistemas, o cargá un valor a mano en Parámetros › Préstamos para los meses que hagan falta.
:::

:::aviso critico
texto: «Nombre» no se proyecta: no hay valor de TAMAR para calcular su tasa.
significa: Ese préstamo variable no tiene con qué calcular su tasa.
corrige: Lo mismo que el anterior.
:::

:::aviso atencion
texto: La última actualización de TAMAR terminó en ERROR (fecha). Se proyecta con el último valor disponible, del dd/mm/aaaa.
significa: El proceso que trae las tasas falló o no encontró datos la última vez que corrió. También puede decir SIN_DATO.
corrige: Avisá a Sistemas si se repite.
:::

:::aviso atencion
texto: El último valor de TAMAR es del dd/mm/aaaa, hace N días hábiles (se toleran 5). Revisá con Sistemas que el proceso de tasas del BCRA esté corriendo.
significa: El último valor es más viejo de lo que se tolera. El BCRA publica con unos días de atraso; si pasa los días tolerados, algo no está corriendo.
corrige: Consultá a Sistemas. La tolerancia se cambia en Parámetros › Préstamos.
:::

:::aviso info
texto: «Nombre»: para las cuotas N no hay suficientes publicaciones de TAMAR antes del inicio del período; se usa el promedio más viejo disponible.
significa: Son cuotas de períodos que empezaron antes de que haya historia suficiente de la tasa.
corrige: Si el banco liquidó otra tasa, corregí la TNA de esas cuotas.
:::

:::aviso atencion
texto: «Nombre» (en dólares): no hay dólar futuro para aaaa-mm. Esos pagos no se pasan a pesos y no suman en el tablero.
significa: La curva del dólar futuro no tiene ese mes ni ninguno cercano.
corrige: Avisá a Sistemas: la curva la carga otro proceso.
:::

:::aviso atencion
texto: «Nombre»: con las correcciones, el capital de las cuotas suma $ X y el préstamo es de $ Y. Revisá las cuotas corregidas contra el cronograma del banco.
significa: Corregiste el capital de cuotas, incluida la última, y la suma no da el capital del préstamo.
corrige: Revisá las cuotas corregidas en **Cronograma**.
:::

:::aviso atencion
texto: «Nombre»: las correcciones de las cuotas N no se aplican porque la cancelación parcial del dd/mm/aaaa rearmó el cronograma. Revisalas contra el del banco; vuelven si se deshace la cancelación.
significa: Después de una cancelación parcial las cuotas cambian, y las correcciones de antes ya no corresponden.
corrige: Corregí las cuotas nuevas con el cronograma que te dé el banco.
:::

:::aviso atencion
texto: No se pudo leer la curva del dólar futuro (…): los préstamos en dólares no se pasan a pesos.
significa: Falló la lectura de la curva.
corrige: Tocá **Actualizar**. Si sigue, avisá a Sistemas.
:::

:::aviso atencion
texto: No se pudo leer la alícuota de IVA de Parámetros › Generales (…): los costos con IVA se calculan con el 21 %.
significa: No se pudo leer la alícuota.
corrige: Revisá Parámetros › Generales.
:::

:::aviso atencion
texto: No se pudo leer el calendario bancario (…): las cuotas se corren al hábil asumiendo hábiles los días de lunes a viernes.
significa: Un feriado no corre ninguna cuota mientras no se pueda leer el calendario.
corrige: Tocá **Actualizar**. Si sigue, avisá a Sistemas.
:::

:::aviso atencion
texto: No se pudieron leer las cuentas bancarias de Saldos (…): no se puede elegir el banco de un préstamo nuevo.
significa: El desplegable de bancos queda vacío.
corrige: Tocá **Actualizar** más tarde.
:::

:::aviso info
texto: Desembolsos de préstamos proyectados: $ X con fecha fuera del horizonte proyectado no se muestran en el tablero.
significa: Hay pagos después del último mes del horizonte. El tablero los informa, pero no tiene columna para mostrarlos. Lo mismo para capital e intereses.
corrige: Nada que corregir.
:::

## Preguntas frecuentes

:::pregunta ¿Por qué no veo en el tablero las cuotas que ya pagué?
Porque ya están en el saldo de los bancos, que el tablero toma de Saldos. Contarlas otra vez las restaría dos veces. Lo de hoy sí va, como real. El cronograma completo, con lo pagado, está en esta pestaña.
:::

:::pregunta ¿Si cambio un costo en Parámetros, cambian mis préstamos?
No. Los préstamos copian los costos y la comisión al darse de alta. Para cambiar un préstamo que ya existe, editalo.
:::

:::pregunta ¿Por qué el interés de una cuota no es la TNA dividido 12?
Porque los bancos argentinos liquidan por días exactos con año de 365. Un mes de 31 días paga más que uno de 28.
:::

:::pregunta ¿Puedo usar el francés con capital trimestral e intereses mensuales?
No: el francés necesita las dos frecuencias iguales. Con frecuencias distintas, usá alemán o bullet.
:::

:::pregunta ¿El desembolso de un préstamo cuenta como ingreso en la tarjeta de Ingresos del tablero?
Sí, porque es plata que entra. Cuando el período tiene desembolsos, el tooltip de la tarjeta dice cuánto.
:::

:::pregunta ¿La fecha sugerida para el desembolso es exacta?
Es el primer día en negativo, o el hábil anterior. Si ese día cae en una columna de meses, el tablero no sabe qué día del mes se pone en rojo: se sugiere el primer hábil del mes y la pantalla lo marca como aproximado.
:::

:::pregunta ¿La pestaña y el tablero dicen lo mismo?
Sí: suman los mismos pagos, columna por columna. En el tablero, cada renglón de Financiamiento se abre en real, proyectado y simulado. Más en [el tablero](ayuda:general_tablero).
:::

## Glosario

| Término | Qué quiere decir |
| --- | --- |
| TNA | Tasa nominal anual: el dato con el que se calculan los intereses |
| TEA | Tasa efectiva anual: la TNA con la capitalización del período |
| CFT | Costo financiero total: la tasa anual que iguala el desembolso con todo lo que se paga |
| TAMAR | Tasa de referencia del BCRA para depósitos mayoristas a plazo fijo de grandes montos |
| BADLAR | Tasa de referencia del BCRA para depósitos a plazo fijo de más de un millón de pesos en bancos privados |
| Spread | Los puntos que se suman a la tasa de referencia en un préstamo variable |
| Sistema francés | Cuota constante; el capital crece cuota a cuota |
| Sistema alemán | Capital fijo; la cuota baja cuota a cuota |
| Bullet | Todo el capital en la última cuota |
| Gracia | Las primeras cuotas, que pagan sólo intereses |
| Desembolso | El día en que el banco acredita el préstamo |
| Corrida | Una cuota que vence un día no hábil y se paga el hábil siguiente |
| Simulado | Un préstamo que todavía no se pidió. No suma en el tablero oficial |
| Finalizado | Un préstamo que ya pagó su última cuota |
| Cancelado | Un préstamo con una cancelación total anticipada ya ocurrida |
| Necesidad | El saldo más bajo del tablero oficial con cobertura, en positivo: lo que falta en el peor momento |
| Margen de seguridad | El % que se suma a la necesidad para el monto sugerido |
| Horizonte | Los meses que proyecta el cashflow |
