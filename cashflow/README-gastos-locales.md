# Módulo Gastos Locales — la caja de los locales

Pestaña **Proveedores → Gastos Locales**, la fila *Gastos Locales* del tablero (sección *Costos Indirectos*), el concepto **Gastos Locales** del cronograma de pagos y la tarjeta **Gastos Locales** de *Parámetros › Generales*.

Rama: `feature/gastos-locales-fleteros-no-habituales`

---

## La idea en una línea

**Lo que los locales propios pagan con la caja se proyecta con el promedio de los últimos 90 días: una semana de gasto por pago en el tramo diario, el día del cronograma, y un importe por mes después, con inflación compuesta desde el mes en curso.**

```
CTA29 / CTA28  (servidor 'locales')            Parámetros › Generales
  egresos (D_H = 'D')                            cronograma GASTOS_LOCALES (lunes, semanal)
  cuentas que empiezan con 5                     cuentas excluidas (522400)
  menos las excluidas                            inflación mensual
  locales PROPIOS y HABILITADOS                          │
  90 días que terminan AYER                              │
        │                                                │
        ▼                                                ▼
  gasto diario de cada local = total de los 90 días / 90
        │
        ├─ tramo diario: una SEMANA por pago = diario × 7 × factor(mes del pago)
        │                el día del cronograma, corrido al hábil SIGUIENTE
        │
        └─ meses:        diario × días del mes fuera del tramo × factor(mes)
        │
        │  factor(M) = Inflacion::compuesta(mapa, mes en curso, M)
        │  un pago con fecha ≤ HOY no se proyecta
        ▼
  una serie por local ──► Horizonte::sumar() ──► GASTOS_LOCALES / PAGOS ──► fila del tablero
```

**Todo es proyección.** No hay parte real que conciliar.

---

## Ejecución de los scripts

Todos contra `central`, en este orden:

| # | Script | Qué hace | Si no se corre |
| --- | --- | --- | --- |
| 1 | `sql/cashflow_gastos_locales.sql` | Siembra `cronograma_gastos_locales_dia` (1, lunes), `cronograma_gastos_locales_frecuencia` (SEMANAL) y `gastos_locales_cuentas_excluidas` (522400), módulo `GENERALES` | La pestaña funciona con los mismos valores por defecto y avisa. No se puede cambiar el día ni la lista de cuentas |
| 2 | `sql/cashflow_estructura_gastos_locales.sql` | Crea la fila `GASTOS_LOCALES` en *Costos Indirectos*, orden 47, `EGRESO`, apuntada a `GASTOS_LOCALES / PAGOS` | El tablero queda como hoy: la pestaña calcula y muestra, pero su total no llega al cuadro |

**No crea ninguna tabla.** Los gastos se leen cada vez; un promedio de 90 días no se guarda, se calcula.

### Después de correrlos

- Dar el permiso **`cashflow.tab.gastos_locales`** a los roles que corresponda, en Gestión de Usuarios. **No hay `cashflow.editar.gastos_locales`**: la pestaña es de sólo lectura. Lo que se edita —el día y las cuentas— está en *Parámetros › Generales* y pide `cashflow.editar.parametros.generales`.
- Mirar el control 2b del script de la fila: si alguna fila de Proveedores Locales usa `PAGOS_TODO` o `PAGOS_FUERA_CRONOGRAMA`, lo pagado con caja podría contarse dos veces (ver *El doble conteo*).

---

## 1. De dónde salen los gastos

`CTA29` (renglones) y `CTA28` (encabezados) en la conexión **`locales`** (base `LOCALES_LAKERS`), con el prefijo de linked server en DEV que ya usa Saldos. `CTA29` existe también en `central` (`LAKER_SA`), pero allá no está `SUCURSALES_LAKERS`: no es la base de la caja de los locales. Verificado el 31/10/2026.

| Filtro | Por qué |
| --- | --- |
| `D_H = 'D'` | Sólo egresos |
| Cuenta que empieza con **5** | Se filtra por cuenta y no por tipo de comprobante: un talonario mal elegido no cambia de qué cuenta sale la plata |
| Menos las del parámetro (hoy **522400**) | La lista se edita en *Parámetros › Generales*, no está en el código |
| `NRO_SUCURS <> 1` | Confirmado por el usuario |
| `CANAL = 'PROPIOS' AND HABILITADO = 1` | El mismo universo que Saldos Locales. Un local que cerró no va a gastar más |
| `FECHA >= hoy − 90` y `FECHA < hoy` | Los **90 días corridos que terminan ayer**, calculados por el servidor (`CAST(GETDATE() AS DATE)`), sin hora |

`COD_CTA` es `FLOAT` en `CTA29`: se lleva a `BIGINT` y a texto en SQL, como hace Saldos, para que `LIKE '5%'` compare contra `'521700'` y no contra `'5.217e+005'`.

### Dos consultas, y la segunda a pedido

La grilla y el tablero sólo necesitan local, cuenta e importe: **la primera consulta ya viene agrupada por (local, cuenta)**, unas ochenta filas, en menos de un segundo. La leyenda, el usuario y el comprobante sólo hacen falta para mirar el detalle de un local, y se piden al abrir su modal (`GastosLocales::comprobantes()`). Ahí la leyenda de un renglón que no la tiene sale de otro renglón del mismo comprobante, como en la consulta original, con un `OUTER APPLY` en vez de una subconsulta por columna.

**El filtro es uno solo** (`GastosLocales::filtro()`) y lo usan las dos consultas: si filtraran distinto, el detalle de un local no sumaría lo que dice su fila.

---

## 2. El cálculo

Vive en `Class/GastosLocalesCalculo.php`, **pura**, con hoy inyectado por el `Horizonte`.

- **Gasto diario de un local = su total de los 90 días ÷ 90.** Siempre 90, también para un local que gastó en pocos días: un día sin gastos también es un dato.
- **Factor de inflación = `Inflacion::compuesta(mapa, mes en curso, M)`.** El mes en curso vale 1. Compuesta y no sumada: es llevar un promedio histórico a moneda de un mes futuro, no reproducir un ajuste pactado (eso es Logística).
- **Tramo diario: una semana por pago** = diario × 7 × factor del **mes de la fecha de pago**.
- **Meses: diario × días del mes posteriores al fin del tramo × factor.**

### Ningún día se cuenta dos veces

Una semana pertenece al tramo si su **fecha teórica** —el lunes, antes de correrlo— cae en el tramo. Los meses suman **sólo los días posteriores al fin del tramo** y no miran los pagos semanales.

Así, si el último lunes del tramo es feriado y el pago se corre al martes, que ya está fuera del tramo, ese pago cae en la columna de su mes —lo decide `Horizonte::acumular()`— pero sigue siendo **la semana del tramo**: el mes no la vuelve a contar, porque el mes cuenta días y no pagos.

| Ejemplo (hoy sábado 31/10/2026, tramo del 31/10 al 27/11) | |
| --- | --- |
| Semanas | lunes 2, **10** (el 9 es feriado), 16 y 23 de noviembre |
| Noviembre (columna del mes) | 3 días: 28, 29 y 30 |
| Diciembre a septiembre 2027 | Los meses enteros |

**El tramo se paga por semanas enteras**, como se pidió: 4 semanas son 28 días aunque el tramo, sin contar hoy, tenga 27. Es la regla, no un redondeo.

### Una fecha de pago igual o anterior a hoy no se proyecta

Esa plata ya está en el saldo que abre el tablero. Se mira la fecha **efectiva**: si hoy es un lunes feriado, el pago se corre a mañana, todavía no salió y **sí** se proyecta. Si hoy es un lunes hábil, ese pago ya se hizo y el tramo tiene tres semanas.

### Inflación faltante: `null` y aviso, nunca cero

Sin el % de algún mes del camino, ese importe queda en `null` con su motivo en el tooltip y el aviso dice qué mes cargar. Los meses que no lo necesitan se proyectan igual.

### Los locales sin egresos van con cero

Un local propio habilitado que no pagó nada con la caja en 90 días va en la grilla con **$ 0** y la leyenda **"sin egresos en 90 días"**. Ese cero es un dato —la consulta respondió y no hay movimientos—, no un faltante. Al 31/10/2026 son dos: XL Rural y Haedo.

---

## 3. El cronograma: el único que se corre hacia adelante

Es el concepto `GASTOS_LOCALES` del cronograma de *Parámetros › Generales* (ver `README-logistica-local.md`, sección 3). Tres diferencias con los otros tres conceptos, declaradas en `CronogramaPagos::CONCEPTOS`:

| | Gastos Locales | Los otros |
| --- | --- | --- |
| Si el día no es hábil | Al hábil **siguiente** (`DiasHabiles::siguiente()`) | Al hábil anterior |
| Frecuencia | **Semanal, fija**: el importe es una semana de gasto | Quincenal o semanal |
| Mover un pago a mano | **No**: nadie mueve el día de un promedio | Sí, con motivo |

**Es una decisión del negocio para este concepto**: el reintegro de la caja de los locales sale el día hábil siguiente. La dirección viaja en la configuración (`'corrimiento'`), así que los otros tres no se enteran; una prueba fija que siguen yendo para atrás.

Por correrse hacia adelante, el calendario se lee **una semana después** del fin del eje: el último pago puede caer feriado y correrse afuera.

En la pantalla del cronograma, Gastos Locales no aparece en *Próximos pagos* (no se editan) y sus fechas se ven en *Resto del horizonte*.

---

## 4. Las cuentas excluidas

Tarjeta **Gastos Locales** en *Parámetros › Generales*: una lista de códigos separados por coma. Vacía quiere decir que entran todas las cuentas 5.

- Se guarda por su propio endpoint (`saveCuentasGastosLocales`), con el permiso de Generales, y se valida en `GastosLocales::validarCuentas()`: sólo números, sin repetidos.
- El guardado genérico de parámetros **la rechaza**: una lista mal escrita dejaría entrar al promedio la cuenta que se quiso sacar.
- Sin el script se usa el defecto (522400) con un aviso.

---

## 5. La pestaña

- **Tres tarjetas**: total de los 90 días (con las fechas de la ventana y cuántos locales), promedio mensual (diario × 30) y lo proyectado **en la vista activa**.
- **La grilla**: una fila por local con su total de 90 días, su gasto diario y el eje en las tres vistas (`EjeVista::armarAgrupado()`). Cada importe tiene su explicación en el tooltip: cuántos días, qué factor y de qué meses. El total al pie.
- **Al abrir un local**: el detalle por cuenta (código, descripción, movimientos, total y diario) y un enlace a sus comprobantes de los 90 días.
- **Sólo lectura.** El día y las cuentas se cambian en *Parámetros › Generales*.
- Avisos con nivel en el panel de siempre, junto con los que el tablero dice de esta pestaña. Exportar en los dos cuadros.

---

## 6. Cuánto pone en el tablero

`GastosLocalesProvider` → `GASTOS_LOCALES` / `PAGOS`, en *Costos Indirectos*, **orden 47**: pegada a *Pagos con Tarjetas y Otros* (45), que tiene los gastos de las supervisoras, y antes del subtotal.

- **La serie es la suma de los locales**: cada local se acumula contra el eje y el total se arma con `Horizonte::sumar()`. Sin búsqueda, el pie de la pestaña y la fila del tablero son el mismo número. Medido el 31/10/2026: **$ 9.177.962,15** en el horizonte (oct-26 a sep-27), los dos.
- **No declara `componentes`**: es un universo propio.
- **Si la consulta falla**, la fila va en cero con un aviso **crítico** que dice que ese cero no significa que los locales no gasten. El contrato del tablero no tiene "sin dato" en una celda: un proveedor que no puede calcular rinde ceros, y lo que sí se puede es decirlo.

### El doble conteo contra Proveedores Locales

Lo que un local paga con su caja puede ser una factura de un proveedor que también está en Cuentas a Pagar Locales. **Con la configuración de hoy no se cuenta dos veces**: la fila de Proveedores Locales usa `PAGOS`, que trae sólo lo que se paga por echeq o transferencia y deja la caja afuera. Si alguien la reapunta a `PAGOS_TODO` o `PAGOS_FUERA_CRONOGRAMA`, lo pagado con caja podría entrar por las dos filas. El script de la fila lo controla e imprime.

---

## Permisos

- `cashflow.tab.gastos_locales`: ver la pestaña. Las dos acciones del controller (`getPlanilla`, `getComprobantes`) son lecturas y además exigen ese permiso (`autorizarLectura`), porque los comprobantes traen leyendas y usuarios de cada caja.
- Lo editable vive en *Parámetros › Generales* y pide `cashflow.editar.parametros.generales`: el día del cronograma (`saveConfigCronograma`) y las cuentas (`saveCuentasGastosLocales`).

---

## Pruebas

```
php cashflow/tests/run.php gastos_locales
php cashflow/tests/run.php cronograma
```

| Archivo | Qué fija |
| --- | --- |
| `tests/test_gastos_locales.php` | El gasto diario por local y por cuenta, el local sin egresos con cero; las semanas del tramo con el lunes y con otro día, el feriado que se corre al **siguiente**, el lunes de hoy que ya se pagó y el lunes feriado de hoy que sí se proyecta; los días del mes fuera del tramo y el mes partido; la semana corrida fuera del tramo que cae en la columna del mes **sin que el mes cuente un día de más**; la inflación compuesta desde el mes en curso y en el mes de pago; la inflación faltante en `null`; sin tramo diario; el proveedor: **la fila es la suma de los locales**, la consulta caída en cero con aviso crítico, un módulo que lanza no tumba el tablero; las cuentas excluidas validadas; los filtros de la consulta; el script de la fila; los permisos |
| `tests/test_cronograma_pagos.php` | El concepto nuevo: lunes semanal, al hábil siguiente (también encadenado y cruzando de mes), sin overrides, frecuencia fija, y que **los otros tres siguen yendo al anterior** |

---

## Archivos

```
Class/
  GastosLocalesCalculo.php      el promedio, las semanas, los meses y la inflación. PURO
  GastosLocales.php             las dos consultas, las cuentas excluidas
  Providers/
    GastosLocalesProvider.php   GASTOS_LOCALES / PAGOS
  CronogramaPagos.php           + el concepto, 'corrimiento', 'frecuencias', 'overrides'
  CronogramaDatos.php           + el rango del calendario y las reglas por concepto
  Parametros.php                + la sección gastos_locales de Generales
Controller/
  GastosLocalesController.php   getPlanilla, getComprobantes (sólo lectura)
  ParametrosController.php      + saveCuentasGastosLocales
Tabs/gastos_locales.php
Js/Gastos-Locales.js
Css/Gastos-Locales.css
sql/
  cashflow_gastos_locales.sql
  cashflow_estructura_gastos_locales.sql
tests/test_gastos_locales.php
```

---

## Pendientes conocidos

- **El permiso `cashflow.tab.gastos_locales` se da a mano** en Gestión de Usuarios.
- **Un lunes del mes anterior al eje no se mira.** Si el último lunes de un mes es feriado, el martes también y hoy es el día 1, el pago corrido a hoy + 1 no se proyecta: el cronograma se calcula desde el mes en curso. Hace falta un feriado de dos días justo en el cambio de mes.
- **La ventana es fija en 90 días.** Si alguna vez hace falta otra, es una constante (`GastosLocalesCalculo::DIAS_VENTANA`), no un parámetro.
