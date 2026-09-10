# Módulo Otros Ingresos

Los ingresos que **no salen de ningún circuito del sistema** y los carga una persona. Hoy hay uno: los dólares de la cuenta comitente.

---

## Por qué es una categoría aparte y no una pestaña más de Ingresos

*Ingresos* agrupa lo que sale de un circuito —ventas, cobranzas, echeqs, cobranzas electrónicas—. Lo de acá se tipea, y la diferencia importa a la hora de leer un número:

| | Un cero en una fila de… | significa |
| --- | --- | --- |
| **Ingresos** | Cobranzas FR | no hay movimientos |
| **Otros Ingresos** | Dólares Cuenta Comitente | **nadie cargó nada todavía** |

Meterlas en la misma bolsa haría indistinguibles esos dos ceros. La categoría además queda armada para que sumar un concepto nuevo sea **agregar una pestaña**, no rediseñar nada: un archivo en `Tabs/`, su entrada en `Menu::$categorias` y su `case` en el controller.

---

## Ejecución de los scripts

Contra `central`:

```sql
-- sql/cashflow_dolares_comitente.sql
```

Crea `RO_T_CASHFLOW_DOLARES_COMITENTE` y deja la fila `DOLARES_COMITENTE` del tablero apuntada a la serie correcta. Es reejecutable.

**El `UPDATE` de la fila no es opcional.** Hasta ahora `DOLARES_COMITENTE` era un proveedor declarado sin módulo, con la serie `DISPONIBLE`; el proveedor nuevo expone `INGRESO`. El validador de estructura rechaza una fila que apunte a una serie que su proveedor no ofrece, así que sin ese `UPDATE` la fila quedaría inválida en cuanto se declara el módulo.

Sin el script, la pestaña **avisa** que falta la tabla y la fila del tablero va en cero. No rompe.

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

Con base, además: que ninguna fecha tenga dos importes vigentes, que es lo que garantiza que la fila del tablero no cuente la misma plata dos veces.

```bash
php tests/run.php otros_ingresos
```

---

## Archivos

```
sql/cashflow_dolares_comitente.sql               Tabla + fila del tablero
cashflow/Class/OtrosIngresos.php                 Lectura, carga y validaciones
cashflow/Class/Providers/OtrosIngresosProvider.php  Conversión a pesos y serie del tablero
cashflow/Controller/OtrosIngresosController.php
cashflow/Tabs/dolares_comitente.php
cashflow/Tabs/exportaciones_tasky.php            Sólo el placeholder
cashflow/Js/OtrosIngresos-Dolares_comitente.js
cashflow/Css/OtrosIngresos-Dolares_comitente.css
tests/test_otros_ingresos.php
```

Modificados: `Class/Menu.php` (categoría nueva + ítem de Exportaciones) · `Class/CashflowRegistry.php` (`DOLARES_COMITENTE` disponible, `EXPORTACIONES` renombrada) · `Controller/TabController.php` (dos pestañas en `$validTabs`) · `sql/cashflow_estructura.sql` y `sql/cashflow_estructura_disponibilidades.sql` (la fila en la semilla).
