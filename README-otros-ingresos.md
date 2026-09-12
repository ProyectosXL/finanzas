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
-- 2. sql/cashflow_saldo_inversiones.sql
```

Crea `RO_T_CASHFLOW_DOLARES_COMITENTE` y deja la fila `DOLARES_COMITENTE` del tablero apuntada a la serie correcta. Es reejecutable.

**El `UPDATE` de la fila no es opcional.** Hasta ahora `DOLARES_COMITENTE` era un proveedor declarado sin módulo, con la serie `DISPONIBLE`; el proveedor nuevo expone `INGRESO`. El validador de estructura rechaza una fila que apunte a una serie que su proveedor no ofrece, así que sin ese `UPDATE` la fila quedaría inválida en cuanto se declara el módulo.

Sin el script, la pestaña **avisa** que falta la tabla y la fila del tablero va en cero. No rompe.

El segundo crea `RO_T_CASHFLOW_SALDO_INVERSIONES` y **crea** la fila `SALDO_INVERSIONES` del tablero, que —a diferencia de la de dólares— no existía. **Toma la sección y el orden de la fila de dólares** en vez de escribirlos a mano: escribir `INGRESOS` en duro dejaría la fila colgando de una sección inhabilitada en una base que ya corrió `cashflow_estructura_disponibilidades.sql`, y la fila no se dibujaría. Es el mismo criterio de `cashflow_estructura_split_cobranzas_fr.sql`. También es reejecutable, y sin él la pestaña avisa y la fila va en cero.

---

## Dólares Cuenta Comitente

### Es un ingreso, no una disponibilidad

El importe **entra al flujo en la fecha que se le carga**. No es un saldo de apertura y no arrastra. Por eso la fila del tablero es de tipo `INGRESO` y no `SALDO_INICIAL`, y por eso vive en la categoría de ingresos y no en la de saldos.

### Se guardan dólares, no pesos

La conversión la hace el proveedor con el **oficial del BCRA**, que ya está resuelto en `Class/Cotizacion.php` sobre `RO_V_DOLAR_OFICIAL_BCRA`. Es el mismo criterio que `ComexProvider` con los pagos al exterior: **el motor nunca ve dólares**.

Guardar pesos congelaría la valuación al momento de la carga. El día que cambie el tipo de cambio, el tablero seguiría mostrando la conversión vieja y no habría forma de notarlo.

- **Cada carga se valúa al T/C de cierre de su propio mes.** No hay un único tipo de cambio para toda la serie: multiplicar todo por un solo valor es otra cuenta —reexpresar la serie a moneda de hoy— y con inflación no se parece.
- **A diferencia de Comex, el T/C no es un parámetro editable.** Un parámetro tiene sentido para valuar un pago futuro, que es criterio comercial; para decir cuánto valen unos dólares que ya están en la cuenta, no.
- **Si para una fecha no hay cotización, se avisa y no se asume un valor.** Ese importe queda fuera de la serie y el aviso dice cuántos dólares son. Inventar un tipo de cambio —el del mes anterior, el último conocido— pondría en el tablero un número que nadie eligió y que nadie podría auditar.

### El importe vigente se pisa, pero el historial queda

Cargar una fecha que ya tiene importe **no hace `UPDATE`**: marca `VIGENTE = 0` las cargas anteriores de esa fecha e inserta una nueva, **todo en una transacción**. Nunca hay baja física, igual que en el resto del módulo. El proveedor y la grilla leen sólo `VIGENTE = 1`.

**El historial no es una auditoría escondida: es lo único que explica por qué el número de ayer era otro.** Con un `UPDATE`, corregir un dedazo y cargar un dato nuevo son indistinguibles después del hecho.

La transacción tampoco es decoración: si la baja confirmara y el alta fallara, la fecha se quedaría **sin importe vigente** y la fila del tablero perdería esa plata en silencio.

En la grilla, el enlace al historial aparece **sólo cuando hay más de una carga** para esa fecha. Un botón que la mitad de las veces abre un modal con una sola fila se lee como una pantalla que no funciona.

### El formulario es mínimo a propósito

Fecha e importe en dólares. Nada más. Todo lo demás —la conversión, la vigencia, el historial— lo resuelve el backend.

- **Cero es un importe válido**: significa que ese día no había dólares en la cuenta, y es un dato distinto de no haber cargado nada.
- **Un negativo se rechaza**: restaría del tablero en vez de sumar.
- **Se aceptan fechas pasadas**, a diferencia de la fecha de cobro manual de Cobranzas FR. La carga describe cuántos dólares había un día dado, y ese día pudo haber sido la semana pasada. Qué hace el eje del tablero con una fecha vieja es asunto del eje.
- **La validación que vale es la del servidor.** El formulario acota lo que se puede tipear, pero lo que manda el navegador es un pedido y no una autorización: el endpoint es alcanzable sin pasar por la pantalla.

---

## Saldo de Inversiones

**Es el mismo circuito que Dólares Cuenta Comitente, copiado a propósito.** Formulario
mínimo (fecha + importe), un importe vigente por fecha, sin baja física, historial desde el
mismo modal, cero válido y negativo rechazado. Todo lo que dice la sección de arriba vale
acá, salvo una cosa.

### Se carga en PESOS, y es una decisión

El campo es `IMPORTE_ARS` y **no hay conversión**: lo que se carga es lo que entra al
tablero.

Los dólares de la cuenta comitente hacen lo contrario —se guardan en dólares y el proveedor
los valúa con el oficial del BCRA de cada mes— y eso **no es una inconsistencia**: ahí el
dato *es* en dólares, y guardarlo en pesos congelaría la valuación. Acá el saldo se informa
en pesos, así que no hay nada que valuar. Convertirlo sería inventarle una moneda de origen
que el dato no tiene, y el síntoma aparecería recién cuando el número del tablero no
coincidiera con el de la pantalla.

Por eso esta pantalla **no muestra ninguna cotización** y la de dólares sí: allá el número
que se ve no es el que llega al tablero, y acá sí.

**Para darlo vuelta**, si algún día el saldo se informa en dólares, son cuatro pasos en
cuatro archivos, y están escritos arriba de `sql/cashflow_saldo_inversiones.sql`: renombrar
la columna, hacer que `OtrosIngresosProvider::saldoInversiones()` convierta con el mapa
mensual de `Cotizacion` —exactamente como `dolaresComitente()`—, poner `'moneda' => 'USD'`
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

Con base, además: que ninguna fecha tenga dos importes vigentes, que es lo que garantiza que la fila del tablero no cuente la misma plata dos veces.

```bash
php tests/run.php otros_ingresos
```

---

## Archivos

```
sql/cashflow_dolares_comitente.sql               Tabla + fila del tablero
sql/cashflow_saldo_inversiones.sql               Tabla + fila del tablero (y el criterio de la moneda)
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
