# Módulo Otros Ingresos

Los ingresos que **no salen de ningún circuito del sistema** y los carga una persona. Hoy hay dos: los dólares de la cuenta comitente y el saldo de inversiones.

---

## Por qué es una categoría aparte y no una pestaña más de Ingresos

*Ingresos* agrupa lo que sale de un circuito —ventas, cobranzas, echeqs, cobranzas electrónicas—. Lo de acá se tipea, y la diferencia importa a la hora de leer un número:

| | Un cero en una fila de… | significa |
| --- | --- | --- |
| **Ingresos** | Cobranzas FR | no hay movimientos |
| **Otros Ingresos** | Dólares Cuenta Comitente | **nadie cargó nada todavía** |

Meterlas en la misma bolsa haría indistinguibles esos dos ceros. La categoría además queda armada para que sumar un concepto nuevo sea **agregar una pestaña**, no rediseñar nada: un archivo en `Tabs/`, su entrada en `Menu::$categorias` y su `case` en el controller.

**Saldo de Inversiones es la prueba de que eso era cierto**: se construyó copiando el circuito completo de Dólares Cuenta Comitente, sin tocar el motor y sin una clase nueva.

---

## Ejecución de los scripts

Contra `central`:

```sql
-- 1. sql/cashflow_dolares_comitente.sql
-- 2. sql/cashflow_dolares_comitente_cronograma.sql   (la fecha de cronograma)
-- 3. sql/cashflow_dolares_comitente_cobertura.sql    (pasa a stock de cobertura)
-- 4. sql/cashflow_saldo_inversiones.sql
-- 5. sql/RO_V_DOLAR_OFICIAL_BCRA_DIARIO.sql          (la cotización diaria)
```

El tercero apaga la fila de Disponibilidades y crea la de **Cobertura**. Va después de `sql/cashflow_cobertura.sql`, que es el que crea esa sección, y aborta diciéndolo si falta. **No migra ningún dato**: las cargas quedan como están y cambia quién las lee.

> El segundo quedó **superado por el tercero**: agrega una columna que, con los dólares como stock, ya no tiene efecto. Se sigue corriendo igual — es lo que deja la tabla como el código la espera — pero la pantalla ya no ofrece esa fecha.

El segundo agrega `FECHA_CRONOGRAMA` y deja las filas que ya están **con la misma fecha en los dos campos**, así que el día que se corre el tablero no se mueve ni un peso. Va después del primero y es reejecutable.

**Sin él la pestaña avisa y va vacía, no rompe.** `OtrosIngresos::estado()` pregunta por la tabla *y* por la columna en la misma consulta, y el aviso distingue los dos casos: "no existe la tabla" y "la tabla no tiene todavía la fecha de cronograma" no se resuelven con el mismo script, y quien ya corrió el primero leería que no existe una tabla que sí existe.

El tercero crea la vista **diaria** del dólar oficial. Sin ella, `Cotizacion::ultimaHasta()` lanza, la pestaña avisa y la columna en pesos va con un guión: los dólares cargados están, lo que falta es a cuánto valuarlos. La fila del tablero se muestra en cero. No rompe.

Crea `RO_T_CASHFLOW_DOLARES_COMITENTE` y deja la fila `DOLARES_COMITENTE` del tablero apuntada a la serie correcta. Es reejecutable.

**El `UPDATE` de la fila no es opcional.** Hasta ahora `DOLARES_COMITENTE` era un proveedor declarado sin módulo, con la serie `DISPONIBLE`; el proveedor nuevo expone `INGRESO`. El validador de estructura rechaza una fila que apunte a una serie que su proveedor no ofrece, así que sin ese `UPDATE` la fila quedaría inválida en cuanto se declara el módulo.

Sin el script, la pestaña **avisa** que falta la tabla y la fila del tablero va en cero. No rompe.

El segundo crea `RO_T_CASHFLOW_SALDO_INVERSIONES` y **crea** la fila `SALDO_INVERSIONES` del tablero, que —a diferencia de la de dólares— no existía. **Toma la sección y el orden de la fila de dólares** en vez de escribirlos a mano: escribir `INGRESOS` en duro dejaría la fila colgando de una sección inhabilitada en una base que ya corrió `cashflow_estructura_disponibilidades.sql`, y la fila no se dibujaría. Es el mismo criterio de `cashflow_estructura_split_cobranzas_fr.sql`. También es reejecutable, y sin él la pestaña avisa y la fila va en cero.

---

## Dólares Cuenta Comitente

### La fecha, y la de cronograma que quedó inerte

`FECHA` es **cuándo se tomó la foto del saldo**, y decide dos cosas: con qué cotización se valúa esa carga, y cuál es la última — que es la que va al tablero. **No se edita desde la grilla**: cambiarla movería el importe en pesos y podría cambiar cuál es el saldo vigente, dos efectos que nadie pide al corregir un número.

> **`FECHA_CRONOGRAMA` ya no hace nada, y es consecuencia de haber pasado a stock.** Existió mientras cada carga era un ingreso que se dibujaba en un día del eje. Un stock no se dibuja en ninguna columna, así que esa fecha dejó de tener efecto y **la pantalla dejó de ofrecerla**. Es el mismo argumento que ya estaba escrito para no dársela al Saldo de Inversiones: *una columna que se puede editar y no cambia nada es peor que no tenerla*.

**La columna no se borra.** Queda en la base, inerte, por el mismo motivo que la serie `INGRESO` queda declarada: para poder volver atrás sin migrar datos. La grilla la sigue mandando tal cual vino al guardar, porque es la clave con la que el backend pisa la carga anterior.

### La vigencia es por día de CRONOGRAMA

La regla era *"un importe vigente por `FECHA`"*. Con dos fechas hay que elegir, y manda la del cronograma: el **significado** de la regla es *"una fila por columna del eje, nada se cuenta dos veces"*, y eso ahora lo decide dónde cae el importe, no cuándo se cargó. La fecha de registro pasa a ser metadato, como `USUARIO` y `FECHA_ALTA`.

Lo resuelve **`OtrosIngresos::claveVigencia()`**, pública y estática para poder probarla sin SQL Server: es una decisión de negocio —qué día se pisa— y no un detalle de la consulta. El historial se lee por la misma clave, porque lo que explica es *por qué el número de esa columna del tablero era otro*, y las versiones de una columna pueden haberse registrado en días distintos.

### Editar no es un `UPDATE`, y mover tampoco

El importe y el día de cronograma se editan **en la grilla**, y los dos pasan por `guardarCarga()`: se marca `VIGENTE = 0` la versión anterior y se inserta una nueva. No hay un endpoint de edición aparte a propósito — insinuaría que hay un camino que modifica en el lugar, y no lo hay. En el historial, una edición aparece como **una versión más**.

> **Mover un importe de día retira el día de origen, en la misma transacción.** Es el caso que no es obvio: sin eso, la fila vieja seguiría vigente en su día y el importe se contaría **dos veces**, una en cada columna. El día de origen lo manda la pantalla en `cronograma_anterior`, porque es la única que sabe de qué fila salió la edición.

Tres cosas que la pantalla cuida, y por qué:

- **La fecha del dato se manda de vuelta tal cual vino.** Si al editar el importe se mandara hoy, cambiaría también la cotización con la que se valúa y el número se movería por algo que nadie pidió.
- **El botón de guardar de cada fila aparece sólo cuando esa fila tiene algo cambiado.** Un botón siempre activo invita a apretarlo, y apretarlo sin cambios generaría una versión idéntica en el historial: ruido permanente sobre el registro que existe justamente para explicar los cambios. El importe se compara **como número**, así que `1000` y `1000.00` no cuentan como cambio.
- **Mover una fila a un día que ya tiene importe lo pisa**, y el mensaje lo dice con las dos fechas. Es lo único que distingue esa pisada de las otras: el usuario no la pidió explícitamente.

### La plomería es compartida, y la diferencia está declarada en un lugar

`guardarCarga()`, `leerVigentes()` y `leerHistorial()` los usan los dos conceptos. La fecha de cronograma entró como **una clave más de la constante del concepto**, que es para lo que esa constante existe:

```php
const DOLARES     = ['tabla' => …, 'campo' => 'IMPORTE_USD', 'cronograma' => 'FECHA_CRONOGRAMA'];
const INVERSIONES = ['tabla' => …, 'campo' => 'IMPORTE_ARS', 'cronograma' => null];
```

Los tres métodos preguntan por `null` en tres lugares y **no se duplica ninguno**. Duplicarlos habría dejado dos transacciones que se pueden desincronizar, y la del alta es la parte delicada.

**Saldo de Inversiones no recibe la columna, y no es un olvido.** Ese saldo es un `STOCK`: su importe **ya** se ubica en el primer día del eje y no en su fecha (`OtrosIngresosProvider::stockInversiones()`). Sus dos fechas ya estaban desacopladas. Darle una columna de cronograma sería darle una columna que no hace nada y que alguien va a editar esperando que haga algo.

### No es un ingreso: es stock de cobertura

> Esto **cambió**. Entraban al flujo como `INGRESO` en la fecha de su carga.

Decir que ese día *ingresa* plata no es cierto: **los dólares ya están en la cuenta**. Lo que hay que decidir es *cuándo se los usa*, y esa decisión se carga en la sección **Cobertura** del tablero. Es exactamente el mismo movimiento que ya había hecho el Saldo de Inversiones, y el script lo copia paso por paso:

| | Fila vieja | Fila nueva |
|---|---|---|
| Inversiones | `SALDO_INVERSIONES` · Disponibilidades · apagada | `STOCK_INVERSIONES` · Cobertura |
| Dólares | `DOLARES_COMITENTE` · Disponibilidades · **apagada** | **`STOCK_DOLARES_COMITENTE`** · Cobertura |

**Funciona sin tocar el motor.** `Cashflow::calcularTotales()` **suma todas** las filas de tipo `STOCK_COBERTURA` para saber con cuánto se puede cubrir; no conoce ninguna por su código. Así que los dólares se suman a las inversiones y el aviso de *"se aplica más cobertura de la que hay"* los cuenta solo.

La fila lleva `COMPUTA = 0` y el motor le vacía las columnas de fecha: un stock no ocurre un día, **está**. El importe se muestra sólo en la columna *Total*.

### El saldo es la última carga, no la suma

Cada carga es una **foto del saldo** a esa fecha, no un depósito. Sumarlas cuenta dólares que nunca estuvieron juntos en la cuenta.

No es teórico — medido sobre las dos cargas que hay hoy:

| | |
| --- | --- |
| Suma de las dos cargas *(lo que hacía la serie vieja)* | **209.940.000** |
| Saldo: la carga más reciente, del 18/09 | **101.310.000** |

La serie `INGRESO` queda declarada para poder volver atrás desde Parámetros sin tocar código, pero las dos son **el mismo dinero mirado de dos formas**: activar las dos filas mostraría el saldo dos veces. Van relacionadas en `componentes` y el validador rechaza la combinación.

En la pantalla, la carga vigente va marcada *al tablero* y el pie dice **SALDO**, no *TOTAL*: un pie que sumara una columna de fotos sería el error más fácil de cometer leyendo esta grilla.

### Se guardan dólares, no pesos

La conversión la hace el proveedor con el **oficial del BCRA**, que ya está resuelto en `Class/Cotizacion.php` sobre `RO_V_DOLAR_OFICIAL_BCRA`. Es el mismo criterio que `ComexProvider` con los pagos al exterior: **el motor nunca ve dólares**.

Guardar pesos congelaría la valuación al momento de la carga. El día que cambie el tipo de cambio, el tablero seguiría mostrando la conversión vieja y no habría forma de notarlo.

- **Cada carga se valúa con la ÚLTIMA COTIZACIÓN CONOCIDA A SU FECHA.** Ver abajo: este criterio cambió.
- **A diferencia de Comex, el T/C no es un parámetro editable.** Un parámetro tiene sentido para valuar un pago futuro, que es criterio comercial; para decir cuánto valen unos dólares que ya están en la cuenta, no.
- **Si para una fecha no hay cotización, se avisa y no se asume un valor.** Ese importe queda fuera de la serie y el aviso dice cuántos dólares son. Inventar un tipo de cambio pondría en el tablero un número que nadie eligió y que nadie podría auditar.

### La cotización es la última conocida a la fecha, no el cierre del mes

> Esto **cambió**. Antes cada carga se valuaba con el tipo de cambio de cierre de su propio mes.

El criterio viejo tenía dos problemas que sólo se veían mirando el número de cerca:

- **El mes en curso no tiene cierre todavía.** `RO_V_DOLAR_OFICIAL_BCRA` colapsa a una fila por año/mes quedándose con el último día cargado, así que para el mes en curso devolvía "la última que haya", o sea otra cosa que lo que su nombre decía.
- **Para una carga de principios de mes se valuaba con una cotización de semanas después.** Nada lo decía.

El criterio nuevo es **`Cotizacion::ultimaHasta($fecha)`**: la última cotización con fecha *anterior o igual* a la de la carga, **junto con el día del que salió**. La fecha es parte del dato: sin ella el importe en pesos no se puede explicar contra nada, y en este país la diferencia entre el dólar de hace tres semanas y el de hoy no es un detalle.

Eso necesitó una vista nueva, porque la que había no servía para esta pregunta:

| Vista | Qué devuelve | Quién la usa | Qué pregunta contesta |
| --- | --- | --- | --- |
| `RO_V_DOLAR_OFICIAL_BCRA` | una fila por año/mes: el cierre | Ventas, Saldos (`mapaMensual`, `delMes`) | *cuánto valió el dólar en ese mes* |
| `RO_V_DOLAR_OFICIAL_BCRA_DIARIO` | la serie diaria completa, sin colapsar | Otros Ingresos (`ultimaHasta`) | *cuánto vale hoy lo que tengo* |

**Las dos son correctas y ninguna reemplaza a la otra**, así que `mapaMensual()` no se borró: Ventas valúa mes a mes porque lo que describe es lo que se vendió en cada mes, y ése sigue siendo el criterio que corresponde ahí. Son dos preguntas distintas con dos respuestas distintas, las dos bien.

**No se rellenan los días sin cotización.** Un sábado se valúa con la del viernes y se muestra *la fecha del viernes*; inventar una fila para el sábado escondería que el dato es de otro día.

### Y es la punta VENDEDORA. Es la única pestaña del cashflow que no usa la compradora

> Esto **cambió**. Antes esta pestaña valuaba con `Comprador`, igual que todas las demás.

Estos dólares están en una cuenta y se miden contra **lo que costaría reponerlos**, que es lo que el banco *cobra* por un dólar. El resto del módulo —Ventas, Saldos, Exportaciones Tasky, Comex— sigue valuando con **comprador** y no se movió.

| | Punta | Quién |
| --- | --- | --- |
| Dólares Cuenta Comitente | `TCV` — vendedor | sólo esta pestaña |
| Ventas, Saldos, Exportaciones Tasky, Comex | `TCC` — comprador | todo lo demás |

**La consecuencia hay que tenerla presente: este total NO cierra contra el de las otras pantallas, y es deliberado.** Con la cotización del 15/09/2026 —comprador 1.480, vendedor 1.530— los 137.000 USD cargados pasan de **202.760.000** a **209.610.000**: 6.850.000 de diferencia que no son un error de nadie.

Por eso **la punta se dice en pantalla**, fila por fila al lado de la cotización y en el pie del KPI en pesos. Un importe valuado a vendedor que no diga que es a vendedor se compara contra el BCRA comprador y parece estar mal. El dato viaja en `TC_PUNTA` desde `valuarDolares()`: la pantalla no tiene una punta escrita, la muestra.

**Cómo está implementado, y por qué así:**

- `Cotizacion::ultimaHasta($fecha, $punta)` — la punta la elige el llamador, **con comprador por defecto**. Por eso agregar el parámetro no movió ni una pantalla.
- `Cotizacion::COMPRADOR` / `Cotizacion::VENDEDOR` son los nombres de las columnas de las vistas: no hay un mapa que mantener, y lo que se intercala en el SQL sale de `Cotizacion::punta()`, que **lanza** ante una punta desconocida en vez de caer en el default. Caer en el default daría un importe apenas más chico sin nada que lo explique.
- `OtrosIngresos::PUNTA` es el único lugar donde está escrito que esta pantalla usa vendedor.
- **`mapaMensual()` y `delMes()` NO tienen el parámetro.** No es un olvido: hoy nadie les pide otra punta, y un método que acepta un argumento que nadie usa es el que un día alguien llama sin entender qué cambia. Agregarlo cuando haga falta es una línea.

**`ultimaHasta()` ya no devuelve la clave `'tcc'`.** Con la punta elegible ese nombre decía *comprador* sobre un valor que puede ser del vendedor. Devuelve `['fecha', 'valor', 'punta']`.

Las dos vistas exponen **las dos puntas** (`Comprador AS TCC`, `Vendedor AS TCV`). Sus encabezados decían que las dos tenían que exponer la misma punta "o los números de dos pantallas del mismo módulo no cerrarían entre sí": eso era cierto mientras todo valuaba con comprador, y quedó explicado en su lugar.

### La cuenta se muestra abierta

La grilla de la pestaña tiene cuatro columnas donde antes tenía una: **USD × cotización (con su fecha y su punta) = importe en pesos**. El pie no suma: muestra el **saldo** —la carga marcada *al tablero*— y es exactamente el importe de la fila del cashflow, así que ese número se puede auditar contra su cotización desde la pantalla.

La cuenta la hace **`OtrosIngresos::valuarDolares()`**, y la usan los dos: el proveedor para armar la serie y el controller para la grilla. Si cada uno multiplicara por su cuenta, los dos totales podrían discrepar y no habría forma de saber cuál está mal.

Cuando la fecha de la cotización no coincide con la de la carga —que es casi siempre— se marca en ámbar: es justo el caso en el que alguien supondría que el tipo de cambio es el del día.

Sin cotización, `TC` e `IMPORTE_ARS` van en **`null`, no en cero**, y la celda muestra un guión. Un cero se leería como "estos dólares valen cero pesos".

### El importe vigente se pisa, pero el historial queda

Cargar un día que ya tiene importe **no hace `UPDATE`**: marca `VIGENTE = 0` las cargas anteriores de ese día e inserta una nueva, **todo en una transacción**. Nunca hay baja física, igual que en el resto del módulo. El proveedor y la grilla leen sólo `VIGENTE = 1`. Qué día se pisa lo dice `claveVigencia()` — ver arriba.

**El historial no es una auditoría escondida: es lo único que explica por qué el número de ayer era otro.** Con un `UPDATE`, corregir un dedazo y cargar un dato nuevo son indistinguibles después del hecho.

La transacción tampoco es decoración: si la baja confirmara y el alta fallara, la fecha se quedaría **sin importe vigente** y la fila del tablero perdería esa plata en silencio.

En la grilla, el enlace al historial aparece **sólo cuando hay más de una carga** para esa fecha. Un botón que la mitad de las veces abre un modal con una sola fila se lee como una pantalla que no funciona.

### El formulario es mínimo a propósito

Fecha e importe en dólares. Nada más. Todo lo demás —la conversión, la vigencia, el historial— lo resuelve el backend.

**El alta sigue teniendo una sola fecha**, y usa la misma para el dato y para el cronograma: quien carga sin elegir cronograma quiere ver el importe el día del dato. Un segundo campo en el formulario obligaría a decidir dos cosas en el caso normal, que es aquel en el que las dos son la misma. El cronograma se ajusta después, en la grilla, que es donde se ve contra qué se lo está moviendo.

- **Cero es un importe válido**: significa que ese día no había dólares en la cuenta, y es un dato distinto de no haber cargado nada.
- **Un negativo se rechaza**: restaría del tablero en vez de sumar.
- **Se aceptan fechas pasadas**, a diferencia de la fecha de cobro manual de Cobranzas FR. La carga describe cuántos dólares había un día dado, y ese día pudo haber sido la semana pasada. Qué hace el eje del tablero con una fecha vieja es asunto del eje.
- **La validación que vale es la del servidor.** El formulario acota lo que se puede tipear, pero lo que manda el navegador es un pedido y no una autorización: el endpoint es alcanzable sin pasar por la pantalla.

---

## Saldo de Inversiones

**Es el mismo circuito que Dólares Cuenta Comitente, copiado a propósito.** Formulario
mínimo (fecha + importe), un importe vigente por fecha, sin baja física, historial desde el
mismo modal, cero válido y negativo rechazado. Todo lo que dice la sección de arriba vale
acá, salvo dos cosas: la moneda, y qué significa el número.

### No es un ingreso: es el stock que respalda la cobertura

> Esto **cambió** con `sql/cashflow_cobertura.sql`. Antes este saldo entraba al flujo como
> un `INGRESO` en la fecha de su carga, y este README y el encabezado del script decían
> justamente eso.

Estaba mal: **que el saldo invertido se informe un día no significa que ese día entre
plata**. La plata ya está, invertida. Lo que hay que decidir es *cuándo se la usa*, y esa
decisión ahora se carga en la sección **Cobertura** del tablero, sobre las columnas que
quedan en rojo. Ver `README-cashflow.md`.

Concretamente:

- La fila `SALDO_INVERSIONES` de Disponibilidades quedó **inhabilitada** (baja lógica; se
  reactiva desde Parámetros, pero mostraría el mismo dinero dos veces y el validador lo
  rechaza).
- En su lugar, la fila `STOCK_INVERSIONES` de la sección Cobertura, de tipo
  `STOCK_COBERTURA`: **no va en ninguna columna de fecha** —es un stock, no un flujo— y su
  importe se muestra sólo en la columna Total.
- El proveedor sirve ahora la serie `STOCK`. La vieja `INGRESO` queda declarada para poder
  volver atrás sin tocar código, y las dos están relacionadas en `'componentes'` del
  registro para que no puedan estar activas a la vez.

**Dólares Cuenta Comitente no se toca:** sigue siendo un ingreso. En el Excel está en el
bloque del Disponible y no en el de inversiones, y es plata en una cuenta, no un fondo
invertido.

### El stock es la ÚLTIMA carga, no la suma de todas

Cada carga es una **foto** del saldo invertido a esa fecha, no un depósito. Dos cargas de
3,5 y 3,6 millones son el mismo dinero informado dos veces, así que el stock es 3,6 y no
7,1.

La serie vieja las sumaba —era un ingreso por fecha— y con más de una carga habría mostrado
plata que no existe. El síntoma no apareció antes sólo porque hasta ahora hay una sola
carga.

### Se carga en PESOS, y es una decisión

El campo es `IMPORTE_ARS` y **no hay conversión**: lo que se carga es lo que entra al
tablero.

Los dólares de la cuenta comitente hacen lo contrario —se guardan en dólares y el proveedor
los valúa con la última cotización oficial conocida a su fecha— y eso **no es una
inconsistencia**: ahí el
dato *es* en dólares, y guardarlo en pesos congelaría la valuación. Acá el saldo se informa
en pesos, así que no hay nada que valuar. Convertirlo sería inventarle una moneda de origen
que el dato no tiene, y el síntoma aparecería recién cuando el número del tablero no
coincidiera con el de la pantalla.

Por eso esta pantalla **no muestra ninguna cotización** y la de dólares sí: allá el número
que se ve no es el que llega al tablero, y acá sí.

**Para darlo vuelta**, si algún día el saldo se informa en dólares, son cuatro pasos en
cuatro archivos, y están escritos arriba de `sql/cashflow_saldo_inversiones.sql`: renombrar
la columna, hacer que `OtrosIngresosProvider::stockInversiones()` convierta con
`Cotizacion::ultimaHasta()` —exactamente como `dolaresComitente()`—, poner `'moneda' => 'USD'`
en el registro y cambiar los rótulos. **Ninguno de los cuatro adivina la moneda del otro**,
y eso es lo que hace que la decisión sea reversible en lugar de un supuesto desparramado.

### La plomería está escrita una vez, no dos

Los métodos públicos van **en paralelo en `OtrosIngresos`** —no hay clase nueva: es un
concepto más de la categoría, no otro modelo de datos— pero las consultas son las mismas,
parametrizadas por la tabla y el nombre del campo, que vienen de dos constantes
(`OtrosIngresos::DOLARES` y `::INVERSIONES`).

Copiar los cinco métodos con otro nombre de tabla habría dejado **dos transacciones que se
pueden desincronizar**, y la del alta es justamente la parte delicada: si la baja confirmara
y el alta fallara, la fecha se queda sin importe vigente y la fila del tablero pierde esa
plata en silencio. Eso tiene que estar escrito una sola vez.

> **El nombre de la tabla se intercala en el SQL, y puede**: es una constante del código y
> no entrada del usuario. Es el mismo criterio de `Ingresos::inSql()` con la lista de
> estados.

### Las dos acciones del controller son propias, no genéricas

`saveDolaresComitente` recibe `importe_usd` y `saveSaldoInversiones` recibe `importe_ars`.
Una acción genérica con un campo `importe` haría que el endpoint acepte **un número sin
moneda**, y quien decide cuál es quedaría escrito en el navegador — que es exactamente lo
que este módulo no hace con ninguna validación.

---

## Exportaciones Tasky

**Tasky es la razón social del grupo en Uruguay.**

Por ahora es **sólo el ítem de menú** con el aviso de sección en construcción: no hay formulario ni datos. El ítem vive en la categoría **Ingresos**, no en Otros Ingresos, porque cuando exista va a salir de un circuito de exportaciones y no de una carga manual.

`Menu::esPlaceholder()` lo detecta por el `include` de `Components/tab_placeholder.php` y lo marca como pendiente **sin que haya que declarar nada aparte**: una declaración que quede vieja se corrige sola.

En el tablero, la fila `EXPORTACIONES` sigue apuntando a un proveedor con `'disponible' => false`: se muestra **en cero y el tablero avisa**, para que el cuadro tenga desde el primer día la forma completa del Excel y se vea qué falta.

---

## Pruebas

`tests/test_otros_ingresos.php`, sin base:

- Las validaciones de importe (cero válido, negativo rechazado, redondeo) y de fecha (pasadas aceptadas, calendario imposible rechazado).
- Que el registro exponga la serie `INGRESO` y **ya no** `DISPONIBLE`, y que el proveedor se instancie.
- Que un proveedor que lanza rinda cero y avise, en vez de tumbar el tablero.
- Que la fila apuntada a `INGRESO` valide y la apuntada a la serie vieja no — que es exactamente lo que arregla el `UPDATE` del script.
- Que el menú detecte `exportaciones_tasky` como placeholder y `dolares_comitente` como pestaña de datos.

Y del Saldo de Inversiones, **lo que puede haberse copiado mal**: que su campo sea
`IMPORTE_ARS` y el de dólares siga siendo `IMPORTE_USD`; que la moneda del registro sea
`ARS` y no `USD`; que el mensaje de importe inválido hable de pesos con la moneda pasada y
siga hablando de dólares sin ella; que la fila valide sola y **conviviendo con la de
dólares**; que su proveedor tampoco pueda tumbar el tablero; y que la pestaña esté
completa de punta a punta — archivo, JS, CSS, script SQL, entrada en `$validTabs` e ítem
del menú contando en el `n/m` de la categoría. Sin esa última, el menú prometería una
pantalla que devuelve 400.

Y de la **valuación**, inyectando una `Cotizacion` de mentira con agujeros en la serie —sábados, domingos, feriados— para no depender de la base: que un día sin cotización tome la anterior *y diga de qué día es*; que una carga posterior a la última cotización cargada use esa última, que es justo lo que el criterio de cierre mensual no podía contestar; y que sin ninguna cotización anterior el tipo de cambio y el importe en pesos queden en `null` y no en cero, con esos dólares informados aparte.

Y del cambio de **ingreso a stock**: que el registro ofrezca la serie `STOCK`, que la vieja `INGRESO` siga declarada para poder volver atrás, y que tener las dos filas activas a la vez sea un error de configuración —es el mismo dinero mostrado dos veces—.

Con base, además: que ninguna fecha tenga dos importes vigentes, que es lo que garantiza que la fila del tablero no cuente la misma plata dos veces.

```bash
php tests/run.php otros_ingresos
```

---

## Archivos

```
sql/cashflow_dolares_comitente.sql               Tabla + fila del tablero
sql/cashflow_saldo_inversiones.sql               Tabla + fila del tablero (y el criterio de la moneda)
sql/RO_V_DOLAR_OFICIAL_BCRA_DIARIO.sql           La cotización diaria, sin colapsar por mes
cashflow/Class/OtrosIngresos.php                 Lectura, carga y validaciones de los dos conceptos
cashflow/Class/Providers/OtrosIngresosProvider.php  Las dos series del tablero
cashflow/Controller/OtrosIngresosController.php
cashflow/Tabs/dolares_comitente.php
cashflow/Tabs/saldo_inversiones.php
cashflow/Tabs/exportaciones_tasky.php            Sólo el placeholder
cashflow/Js/OtrosIngresos-Dolares_comitente.js
cashflow/Js/OtrosIngresos-Saldo_inversiones.js
cashflow/Css/OtrosIngresos-Dolares_comitente.css
cashflow/Css/OtrosIngresos-Saldo_inversiones.css
tests/test_otros_ingresos.php
```

Modificados: `Class/Menu.php` (categoría nueva + ítem de Exportaciones + ítem de Saldo de Inversiones) · `Class/CashflowRegistry.php` (`DOLARES_COMITENTE` disponible, `EXPORTACIONES` renombrada, `SALDO_INVERSIONES` nuevo) · `Controller/TabController.php` (tres pestañas en `$validTabs`) · `sql/cashflow_estructura.sql` y `sql/cashflow_estructura_disponibilidades.sql` (la fila en la semilla).
