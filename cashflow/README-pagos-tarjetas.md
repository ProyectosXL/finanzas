# Módulo Pagos con Tarjetas y Otros

Pestaña **Financiero → Pagos con Tarjetas y Otros**, con sus tres sub-pestañas; la sub-pestaña **Parámetros › Tarjetas**; y la fila *Pagos con Tarjetas y Otros* del tablero, en Costos Indirectos.

Rama: `feature/financiero-pagos-tarjetas`

---

## La idea en una línea

**Tres circuitos distintos terminan en el mismo lugar —una tarjeta que se debita una vez por mes— y por eso comparten el maestro de tarjetas, los resúmenes y la fila del tablero.**

```
  RO_T_GASTOS_SUPERVISION          CPA04 + CPA54 (via              RO_T_CASHFLOW_TARJETAS_RESUMEN
  (el histórico, autorizado)        Proveedores::getPendientes)     (los últimos 3, cualquier origen)
          │                                 │                                 │
          ▼                                 ▼                                 ▼
  promedio de 3 meses              facturas TARJETA CORP             promedio de 3 resúmenes
  × inflación compuesta            + cobertura de la tarjeta         × cobertura
          │                                 │                        $ × inflación · U$S × dólar
    ┌─────┴─────┐                           │                                 │
    ▼           ▼                           │                                 │
 efectivo    tarjeta                        │                                 │
 partes      día de vto                     │                                 │
 los lunes   de la tarjeta                  │                                 │
    │           │                           │                                 │
    └─────┬─────┘                           │                                 │
          ▼                                 ▼                                 ▼
     SUPERVISORAS                      CORPORATIVAS                       SOCIOS
          └─────────────────────┬──────────────────┘                          │
                                └──────────────┬───────────────────────────────┘
                                               ▼
                                             TOTAL
                                               │
                        Horizonte::acumular() ─┴─►  fila «Pagos con Tarjetas y Otros»
                                                    (Costos Indirectos, orden 45)
```

**Un resumen cargado pisa la estimación de su mes, en los tres casos**, con su importe y con su fecha.

---

## Para pasar a producción: los scripts, en orden

Todos contra `central`, reejecutables, y ninguno borra nada.

| # | Script | Qué hace | Si no se corre |
| --- | --- | --- | --- |
| 1 | `sql/cashflow_tarjetas.sql` | Crea `RO_T_CASHFLOW_TARJETAS` y `RO_T_CASHFLOW_TARJETAS_RESUMEN`. **No siembra ninguna tarjeta** | La pestaña **no falla**: las tres sub-pestañas se ven vacías avisando qué script falta, y la sub-pestaña de Parámetros se dibuja apagada. La fila del tablero va en **cero** y el proveedor lo dice |
| 2 | `sql/cashflow_tarjetas_facturas.sql` | Crea `RO_T_CASHFLOW_TARJETAS_FACTURA` y `RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA` | Pagos Corporativos **se lee igual** —las facturas salen de Tango— pero no se puede vincular ni excluir ninguna: los botones de la barra se dibujan apagados nombrando el archivo, no hay cobertura y nadie está excluido |
| 4 | `sql/cashflow_tarjetas_vto_mensual.sql` | Crea `RO_T_CASHFLOW_TARJETAS_FACTURA_VTO` (el vencimiento corregido de una cuota) y `RO_T_CASHFLOW_TARJETAS_MENSUAL` (las facturas marcadas como mensuales). **Corta si falta el 1**: la segunda tiene una FK contra el maestro | Pagos Corporativos se lee igual: los controles de **corregir el vencimiento** y **marcar mensual** se dibujan apagados nombrando el archivo, todas las cuotas usan el vencimiento de Tango y no hay estimaciones. El tablero no cambia |
| 3 | `sql/cashflow_tarjetas_fila.sql` | Crea la fila `TARJETAS_PAGOS` en *Costos Indirectos*, orden 45, apuntada a `TARJETAS` / `TOTAL` | **El tablero queda exactamente como hoy** y no falta nada, porque hasta hoy esta plata no estaba en el cuadro. La pestaña funciona igual y avisa que lo que muestra todavía no llega al tablero |

El **2 y el 4 dependen del 1** (tienen una FK contra el maestro) y cortan con un mensaje si falta. El 3 no depende técnicamente de los otros dos, pero conviene último: una fila activa apuntada a un proveedor sin tablas muestra un cero.

**La vista `RO_V_CASHFLOW_USUARIOS_TARJETAS` no la crea ningún script de este repo.** Ya existe y se mantiene aparte. El script 1 sólo verifica que esté con `OBJECT_ID` y avisa si falta; la pantalla hace lo mismo y se dibuja apagada en vez de fallar.

### Después de correrlos

- **Dar de alta las tarjetas** en *Parámetros › Tarjetas*. Hasta que no haya ninguna, la parte tarjeta de las supervisoras no entra al tablero (hoy **$ 60.118.164,98** en todo el horizonte) y ninguna factura corporativa genera cobertura.
- Para las de tipo **SUPERVISORA**, el nombre del usuario tiene que coincidir con el de la supervisora en `RO_T_SUPERVISORAS_COMERCIAL`: es por nombre que se asocian. Ver la sección 3.
- **Vincular las facturas corporativas** a su tarjeta. Desde `feature/cronogramas-tarjetas-corporativas`, **ninguna factura sin tarjeta entra al flujo**, vencida o no (ver la sección 4).
- Verificar que **ninguna fila activa de Proveedores Locales** use `PAGOS_TODO` ni `PAGOS_FUERA_CRONOGRAMA`. Las dos incluyen las facturas de tarjeta corporativa, que desde el script 3 entran por acá: el mismo peso se contaría dos veces. El script lo verifica e imprime el resultado. Ver la sección 7.

---

## 1. Las cuatro tablas

| Tabla | Qué guarda | Clave |
| --- | --- | --- |
| `RO_T_CASHFLOW_TARJETAS` | El maestro: tipo, banco, usuario, últimos 4, % de cobertura y día de vencimiento | `ID`, con identidad única `(COD_BANCO, ID_USUARIO, ULTIMOS_4)` |
| `RO_T_CASHFLOW_TARJETAS_RESUMEN` | Un resumen por tarjeta y mes de vencimiento | Único filtrado `(ID_TARJETA, MES) WHERE ACTIVO = 1` |
| `RO_T_CASHFLOW_TARJETAS_FACTURA` | Qué factura se paga con qué tarjeta | Único filtrado `(COD_PROVEE, T_COMP, N_COMP) WHERE ACTIVO = 1` |
| `RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA` | Qué factura no entra a esta pestaña, y por qué | Único filtrado por comprobante `WHERE VIGENTE = 1` |

**Las cuatro llevan auditoría** —`USUARIO_ALTA`, `FECHA_ALTA`, `USUARIO_MODIF`, `FECHA_MODIF`— y las tres con baja lógica además `USUARIO_BAJA` y `FECHA_BAJA`. **Las escribe siempre el backend** con el usuario de la sesión; el front no manda ninguna.

### La identidad de una tarjeta, y por qué el índice no está filtrado

`(COD_BANCO, ID_USUARIO, ULTIMOS_4)`, **sin filtrar por `ACTIVA` y sin el `TIPO`**. Las tres decisiones tienen su motivo:

- SQL Server trata los `NULL` como iguales en un `UNIQUE`, así que **la segunda tarjeta del mismo usuario en el mismo banco sin los últimos 4 la rechaza la base**. Es exactamente la regla: ahí pasan a ser obligatorios. El backend lo dice antes y mejor, pero la red está.
- **Sin filtrar por `ACTIVA`**, dar de baja una tarjeta y volver a cargarla *reactiva la misma fila* en vez de crear una segunda. Así los resúmenes viejos siguen colgando de la tarjeta que los tuvo, que es lo que la baja lógica promete conservar.
- **El tipo no participa**: dos filas con el mismo banco, usuario y últimos 4 pero distinto tipo serían la misma tarjeta física cargada dos veces, y sus resúmenes se contarían dos veces en el tablero.

### `DIA_VENCIMIENTO` es obligatorio, al revés que las horas de un fletero

En Logística los tres números nacen en `NULL` porque son lo que se negocia y puede no estar acordado. Acá es al revés: el día de vencimiento **lo fija el banco** y se sabe en el momento en que la tarjeta existe.

Y sin él **no hay ninguna fecha donde poner la estimación**: una tarjeta con el día en `NULL` no proyectaría un peso en ningún mes, así que dejarlo opcional sólo habilita crear una tarjeta que no puede servir para nada. No tiene `DEFAULT`: convertiría la falta de dato en un dato.

### Una tarjeta no tiene moneda

La misma tarjeta puede tener consumos en pesos y en dólares, así que la moneda vive en el **resumen** —`IMPORTE_ARS` e `IMPORTE_USD`, con al menos uno cargado—. Un campo de moneda en el maestro obligaría a dar de alta dos tarjetas para la misma tarjeta física, y entonces el número del resumen no coincidiría con ninguna de las dos.

### Un resumen no puede ser cero ni negativo

Los dos importes son opcionales —al menos uno tiene que estar— pero cuando están, son `> 0`. Un cero no se distingue de un campo vacío, que ya significa *"ese mes no hubo consumos en esa moneda"*. Y un negativo —un saldo a favor— **convertiría un `EGRESO` del tablero en un ingreso que nadie afirmó**, en una fila cuyo `TIPO` es `EGRESO`. Si algún día hay que modelarlo, es una decisión y no una carga.

---

## 2. El tipo de tarjeta no es quién es el usuario

`RO_V_CASHFLOW_USUARIOS_TARJETAS` une **directores y supervisoras activos** sin columna de tipo, más una fila fija. Al 26/09/2026 son 14 filas y `GROUP BY ID_DIRECTOR HAVING COUNT(*) > 1` **no devuelve ninguna**: por eso `ID_USUARIO` alcanza como referencia al usuario y no hace falta una clave compuesta.

> **Los dos espacios de IDs son disjuntos por accidente**, no por construcción: las supervisoras van de 1 a 11 y los directores de 1115 en adelante. El script 1 corre ese control e imprime el resultado, para que el día que se toquen se vea.

**El `TIPO` es una decisión independiente de quién sea el usuario.** La gerenta de administración y finanzas figura en la vista al lado de las supervisoras y su tarjeta es **CORPORATIVA**. El tipo decide dos cosas: en qué sub-pestaña aparece y con qué regla se estima.

**Sólo las de tipo `SUPERVISORA` se cruzan por nombre** contra el maestro de supervisoras. Para los otros dos tipos el nombre del usuario es descriptivo y no cruza contra nada.

### El nombre se guarda como copia

Sale de la vista y **no se tipea**: si se pudiera, dos pantallas mostrarían dos nombres para el mismo ID y ninguno sería *"el nombre del usuario"*. Se guarda igual, para que la tarjeta siga siendo legible el día que ese usuario deje de estar activo en la vista —y entonces desaparezca de ella—, porque un ID suelto no le dice nada a nadie. La pantalla muestra el de la vista cuando lo encuentra, así que un cambio de nombre se ve enseguida.

Una tarjeta cuyo usuario ya no está en la vista **se marca y no se da de baja sola**: puede ser una supervisora que dejó de estar activa, y decidir qué hacer con su tarjeta es de una persona. Mismo criterio que un fletero cuyo código ya no está en `CPA01`.

### El banco se busca, y las dos listas van ordenadas por nombre

`BANCO` tiene **198 filas**, así que el banco no es un desplegable: es un campo que se escribe y filtra las coincidencias, con el mismo patrón que el alta del maestro de fleteros. Busca **por nombre y por código**, porque quien carga una tarjeta puede acordarse de cualquiera de los dos, y **filtra lo que ya vino en el payload**: una consulta por cada letra tipeada sería ir a buscar algo que ya está en la pantalla.

El **código elegido viaja en un campo aparte** del texto que se escribe. Eso es lo que hace que el buscador sea seguro: el input muestra el nombre —es para buscar— y lo que se manda al servidor es el código. Escribir **invalida lo elegido**, porque si no, corregir el texto después de haber elegido dejaría el código anterior guardado y se daría de alta la tarjeta en un banco que la pantalla ya no muestra.

> **Las dos listas viajan como lista y no como mapa, y no es un detalle de forma.** Un mapa `clave => nombre` se convierte en un objeto JSON, y `Object.keys()` en JavaScript **no** devuelve las claves en el orden en que se escribieron: pone primero las que son índices de array —enteros canónicos— ordenadas numéricamente.
>
> No es teórico: **de los 198 códigos de `BANCO`, 135 son enteros canónicos** (`151`, `295`, `313`…), así que el desplegable salía ordenado por número de banco aunque la consulta diga `ORDER BY DESC_BANCO`. Con los usuarios pasaba lo mismo: salían por ID en vez de por nombre.
>
> `Tarjetas::comoLista()` devuelve una lista de objetos, que conserva el orden en JSON y en JavaScript, y **reordena por nombre igual** aunque la consulta ya lo haga: ordenar dos veces no cuesta nada sobre doscientas filas, y no ordenar cuesta un desplegable que nadie puede recorrer. El orden es natural y no distingue mayúsculas, porque los nombres vienen de Tango tal como los tipearon y *"de Galicia"* y *"DEUTSCHE"* tienen que quedar juntos donde alguien los busca.

### El % de cobertura se aplica sobre la base estimada, y en Corporativas no

Va en **puntos** —5 es 5 %, igual que la inflación mensual y al revés que la alícuota de IVA, que se guarda como tasa—. Se aplica en los tres tipos, **sobre la base estimada de cada uno**:

| | Sobre qué | Por qué |
| --- | --- | --- |
| **Supervisoras** | La parte tarjeta del estimado | Es una estimación: multiplicarla es corregir una estimación |
| **Socios** | Los dos componentes, `$` y `U$S` | Ídem |
| **Corporativas** | **Nada. Entra como renglón aparte** | La base son **facturas reales de Tango**, y multiplicarlas inventaría deuda sobre un comprobante que existe |

En Corporativas la cobertura sale como una fila propia —*"Cobertura gastos excepcionales"*— por tarjeta y mes, en el día de vencimiento de la tarjeta. Es la misma regla de negocio expresada de la única forma que no falsea el dato de origen.

---

## 3. Gastos Supervisoras

### Es consistente con el dashboard de supervisión, a propósito

El mismo dato lo muestra el dashboard de presupuesto de supervisión (repo `ProyectosXL/comercial`, `supervision/presupuesto/Class/Dashboard.php`), y los dos números tienen que poder compararse. Por eso se copian sus tres criterios:

| Criterio | Cuál es | Por qué importa |
| --- | --- | --- |
| **Estado** | `ESTADO = 1`, sólo los **autorizados** | Lo no autorizado todavía no es un gasto que vaya a salir de caja: es un pedido |
| **El mes** | `MONTH(FECHA_MODIF)` / `YEAR(FECHA_MODIF)`, **no la semana** | La tabla tiene `SEMANA` y `ANIO`, que es lo que se tipea, pero una semana puede caer en dos meses: los dos criterios dan distinto y sólo uno coincide con el dashboard |
| **El importe** | `IMPORTE` (efectivo) `+ IMPORTE_TARJETA` | — |

> `ESTADO` es un **`bit`**: los únicos valores posibles son `1` y `NULL`. Al 26/09/2026 hay 1.371 filas en `1` y una sola en `NULL`.

### El presupuesto y los adelantos no se leen

- **`RO_T_PRESUPUESTOS_SUPERVISION` se ignora.** Dice cuánto se **autorizó a gastar**, y el cashflow necesita cuánto se **va a gastar**. Las dos cosas difieren y la que predice la caja es la segunda.
- **`FU_T_ADELANTOS_SUPERVISION` tampoco.** Ese circuito ya no se usa.

### La ventana son tres meses calendario completos, y se calcula sola

Los tres anteriores al mes en curso. Hoy (26/09/2026): **junio, julio y agosto de 2026**.

**El mes en curso no entra**, y ese es el error que la prueba existe para atrapar: está incompleto, así que incluirlo bajaría el promedio por los días que todavía no pasaron, y lo bajaría más cuanto más cerca del día 1 se mirara la pantalla. El número cambiaría solo, todos los días, y nada en la pantalla lo explicaría.

Se calcula con el calendario y **no se guarda en ningún parámetro**: una ventana materializada se desactualiza sola el 1 de cada mes.

### Siempre se divide por tres

También cuando la supervisora tiene gastos en uno o dos meses de la ventana, **porque un mes sin gastos también es un dato**: una supervisora que entró en agosto gasta, en promedio, un tercio de lo que gastó en agosto. Dividir por los meses con datos daría su gasto de un mes típico y proyectaría **el triple** todos los meses del horizonte.

Igual se avisa cuántos meses con datos tiene, porque un promedio sobre un solo mes es mucho menos firme que uno sobre tres y el número no lo dice.

> Es la regla **contraria** a la de Tarjetas Socios, y las dos están bien. Ver la sección 5.

### La proporción sale de la ventana, no de cada mes

`% efectivo = Σ efectivo / Σ total de la ventana`, y `% tarjeta` es **el complemento**, no una segunda división: así las dos partes suman exactamente el estimado, sin un centavo perdido en el redondeo de dos cocientes.

Una proporción por mes haría que un mes atípico —uno en el que casualmente no se usó efectivo— se proyectara hacia adelante para siempre.

### El ajuste por inflación es compuesto

`estimado(m) = promedio × Π (1 + inf_k / 100)`, con `k` desde el mes siguiente al mes base hasta `m`. El **mes base es el último de la ventana** —el promedio está en moneda de ese mes—, así que el producto arranca en el siguiente.

Lo resuelve `Inflacion::compuesta()`, que es una función nueva **al lado de** `acumulada()` y no en lugar de ella:

| | Qué hace | Para qué |
| --- | --- | --- |
| `acumulada()` | **Suma sin componer** su mes y los dos anteriores | Es el % de un **ajuste pactado**: se enuncia como *"la suma de la inflación del trimestre"*, y componer daría 6,12 % donde la negociación dice 6 % |
| `compuesta()` | **Multiplica** `(1 + inf/100)` mes a mes | Lleva un **promedio histórico** a moneda de un mes futuro, donde no hay nada pactado y el segundo mes sube sobre el primero ya aumentado |

Las dos conviven porque las dos están bien, y la prueba compara sus resultados sobre el mismo trimestre para que nadie las unifique. Usar la equivocada no falla: sólo da un número que nadie puede explicar contra su papel.

### Cuatro motivos para no proyectar, y ninguno es cero

| Motivo | Cuándo |
| --- | --- |
| `SIN_SUPERVISORA` | El nombre no está en `RO_T_SUPERVISORAS_COMERCIAL` |
| `INACTIVA` | Está pero con `ACTIVA = 0`: dejó de trabajar |
| `SIN_IMPORTE` | Tiene filas en la ventana pero suman cero |
| `SIN_INFLACION` | Falta el % de algún mes del camino (**por mes, no por supervisora**) |

**El estado manda sobre la inflación**: si no proyecta por su estado, decir *"falta la inflación de octubre"* mandaría a cargar un dato que no cambiaría nada.

El promedio **se sigue mostrando** aunque no proyecte: es lo que el aviso necesita para decir cuánto deja de proyectarse.

### El universo son las supervisoras con gastos en la ventana

Una supervisora **activa sin gastos en la ventana no genera fila**: sale en un aviso con su nombre. Darle una fila con promedio cero la proyectaría en cero por doce meses, que es exactamente el cero que este módulo no pone —y además puede ser alguien que no cargó sus gastos, que es plata que el tablero no está proyectando.

Hoy es el caso de **JULIETA DALMEIDA**: activa, con su último gasto el 11/03/2026.

### Cuándo sale de caja

- **Efectivo** = `estimado(m) × % efectivo`, en **partes iguales** en los pagos del mes del cronograma **`SUPERVISORAS`** de *Parámetros › Generales* —por defecto **todos los lunes del mes**, corridos al día hábil **anterior**—: un mes de cinco lunes reparte en quintos y uno de cuatro en cuartos. Hasta `feature/cronogramas-tarjetas-corporativas` era el mismo de Logística, el 2do y el 4to viernes, mitad y mitad; ahora cada concepto tiene su cronograma (ver `README-logistica-local.md`, sección 3). La parte se calcula sobre **todos** los pagos del mes, también los que ya pasaron, y **no se redondea**: redondear haría que un importe impar perdiera un centavo por mes, todos los meses.
- **Un pago con fecha anterior o igual a hoy no se proyecta.** Esa plata ya salió y ya está en el saldo bancario que abre el cuadro. Consecuencia, y hay que tenerla presente: **la columna de hoy nunca recibe nada de la parte en efectivo**.
- **Tarjeta** = `estimado(m) × % tarjeta × (1 + PCT_COBERTURA / 100)`, en el `DIA_VENCIMIENTO` de **la tarjeta tipo SUPERVISORA de esa supervisora**.

### La tarjeta se asocia por el nombre del usuario

`RO_T_GASTOS_SUPERVISION.SUPERVISORA` guarda el **nombre**, no un ID, así que el cruce es por nombre —igual que hace el dashboard—. La clave la normaliza `GastosSupervision::clave()`: mayúsculas y sin espacios de más, **la misma función en los dos lados del cruce**. Los acentos no se normalizan ahí porque la collation de las dos columnas (`Modern_Spanish_CI_AI`) ya es acento-insensible, y normalizar dos veces y de dos formas distintas es lo que deja de coincidir.

**Más de una tarjeta activa a su nombre es ambiguo** y se trata como *sin tarjeta*: elegir la primera pondría el importe en una fecha que puede no ser la correcta y nadie tendría dónde notarlo.

**Sin tarjeta, la parte tarjeta no entra al tablero** y se avisa con el importe. Sin tarjeta no hay día de vencimiento, y no se inventa una fecha. La parte en efectivo entra igual.

> Al 26/09/2026, con ninguna tarjeta cargada: seis supervisoras proyectan **$ 1.537.183,69** de efectivo y quedan **$ 60.118.164,98** de parte tarjeta fuera del tablero.

---

## 4. Tarjetas Pagos Corporativos

### El universo sale de `getPendientes()`, sin una segunda consulta

Las facturas pendientes de Tango cuya **forma de pago vigente** es `TARJETA CORP`, leídas con `Proveedores::getPendientes()` —la misma consulta de Cuentas a Pagar Locales—. **No se escribe una segunda consulta a CPA04/CPA54**: la que existe reproduce a Tango al centavo, con la tabla de signos de las imputaciones, que es la parte fácil de hacer mal, y duplicarla sería duplicar ese riesgo.

**Vigente** quiere decir el override por factura de Proveedores Locales (`FORMA_PAGO_CRONOGRAMA`) si lo hay, y si no la forma del maestro. Esa resolución ya viene hecha en `FORMA_PAGO_VIGENTE` y no se rehace: dos versiones de la misma regla clasificarían distinto la misma factura en dos pantallas. Hoy hay exactamente una factura que entra por el override: `OGHALL FAC A0000100008942`.

Al 26/09/2026 el universo son **135 vencimientos por $ 89.896.563,43 en 94 comprobantes**, de los cuales **90 están vencidos**.

### Sólo las vinculadas entran al flujo

> Esto **cambió** en `feature/cronogramas-tarjetas-corporativas`. Antes las no vinculadas no vencidas entraban por su vencimiento de Tango.

**Una factura sin tarjeta vinculada no se proyecta, esté vencida o no.** Sigue viéndose como fila, con su *PENDIENTE*, su vencimiento —que se puede corregir— y su estado *sin tarjeta: no entra*, pero **no suma**: ni en las columnas del eje de la grilla —que van vacías, no en cero—, ni en *TOTAL PERÍODO*, ni en el pie, ni en la serie `CORPORATIVAS` del tablero. Sin tarjeta no se sabe en qué débito sale, y una factura de tarjeta se paga en el débito de su tarjeta.

**Vincularla a una tarjeta** hace que entre —por su vencimiento, o si ya venció, en el próximo pago de la tarjeta—, que genere cobertura y que un resumen la pueda reemplazar.

El motivo pasó de `VENCIDA_SIN_TARJETA` a **`SIN_TARJETA`**, y la precedencia queda **`EXCLUIDA` > `CUBIERTA` > `SIN_TARJETA` > `OK`**.

### La fecha es el vencimiento de Tango, y no la de Proveedores Locales

Esta pestaña **no sigue la jerarquía de `Proveedores::resolverFechaPago()`**, que prefiere la fecha de pago cargada a mano. Es una diferencia deliberada: el pago de una tarjeta lo fija el banco, no una fecha que alguien cargó pensando en un echeq.

> **La consecuencia hay que tenerla presente: la misma factura puede verse en fechas distintas en las dos pestañas.** Hoy son 10 de las 135, que tienen una `FECHA_PAGO` cargada en Proveedores Locales.

### Lo vencido no se apila en el primer día del eje

Y ahí está la diferencia más grande con Proveedores Locales, que sí lo hace. **Una tarjeta se paga una vez por mes:**

| | Qué pasa |
| --- | --- |
| **Vencida y vinculada** | Sale en el **próximo pago de su tarjeta** —el resumen cargado si lo hay, o el próximo `DIA_VENCIMIENTO` posterior a hoy—. De ahí en más se comporta como cualquier factura de ese mes: entra en su cobertura y la pisa su resumen |
| **Vencida sin vincular** | **No se proyecta**, como ninguna sin vincular, y se avisa con el conteo y el importe. Apilarla en el día uno afirmaría que se paga hoy |

Con el criterio de Proveedores Locales, más de **$ 17 millones** caerían hoy en la columna del 26/09, en una fila que además lleva las estimaciones.

El próximo pago lo resuelve `TarjetasVencimiento::proximoPago()`, que **mira resúmenes y estimaciones juntos** y se queda con el más temprano posterior a hoy: un resumen del mes en curso que vence en tres días es el próximo pago, y mirar sólo las estimaciones correría la fecha un mes entero. Un resumen pagado no cuenta, porque ya salió.

### La cobertura

```
cobertura(t, m) = PCT_COBERTURA(t) / 100 × Σ facturas vinculadas a t que se pagan en m
```

- Se ubica en el **`DIA_VENCIMIENTO` de la tarjeta**, no en la fecha de cada factura: es un gasto de la tarjeta, que se debita una vez por mes.
- **Sólo sobre las que efectivamente suman.** Una excluida, una cubierta por un resumen o una sin vincular no está en la fila, así que no necesita cobertura: calcularla sumaría un importe que acompaña a nada.
- **Las no vinculadas no generan cobertura**: no se sabe con qué tarjeta se pagan, así que no hay % que aplicar.
- **Las estimaciones mensuales que proyectan sí**: se comportan como una factura vinculada de su tarjeta y su mes, y el tooltip las cuenta aparte.
- Una tarjeta con **0 % no genera ninguna fila**, y no una fila en cero: una fila de cobertura en cero se lee como *"esta tarjeta tiene cobertura y este mes no la usa"*, que es otra cosa.

### El resumen reemplaza las vinculadas de su mes, y su cobertura

El resumen es lo que el banco va a debitar: si está cargado, las facturas vinculadas que se pagan en ese mes ya están adentro. **Siguen viéndose en la grilla, marcadas *"cubierta por resumen"*, pero no suman.**

**El aviso de POSIBLE DOBLE CONTEO se fue** con `feature/cronogramas-tarjetas-corporativas`. Avisaba de las no vinculadas de un mes con resumen, que podían estar adentro del resumen y contarse dos veces; **como las no vinculadas ya no suman, ese riesgo no existe**.

### El vencimiento de Tango se puede corregir, sólo en esta pestaña

Quien carga en Tango **ya no pone en `FECHA_VTO` el vencimiento real de la factura sino el del resumen de la tarjeta** en el que se va a pagar. Si lo pone mal, la cuota cae en otro débito. La columna **VTO TANGO** se puede corregir con el mismo patrón que la fecha de Proveedores Locales: un input que guarda al cambiar y un **↺ que vuelve al de Tango**.

- **La clave es la cuota**, `(COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO)`: una factura en cuotas tiene varias filas y cada una se corrige por separado. Tabla `RO_T_CASHFLOW_TARJETAS_FACTURA_VTO`, con índice único filtrado por `VIGENTE = 1`, auditoría completa y **sin borrado físico**: deshacer marca `VIGENTE = 0`.
- **La fecha corregida reemplaza a la de Tango en toda la lógica**: si está vencida, la reubicación al próximo pago de la tarjeta, el mes de pago, la cobertura y el reemplazo por resumen. **Una vencida corregida a una fecha futura deja de estar vencida.**
- **La fecha mínima es hoy**, validada en el backend (`TarjetasCorporativas::validarVtoEditado()`); el `min` del input es comodidad. **El motivo es opcional**: se pide en un diálogo y se puede dejar vacío.
- **Sólo vale en esta pestaña**: la fecha de pago de Cuentas a Pagar Locales no se entera. Se puede corregir en filas vinculadas y no vinculadas.
- Corregida **se ve distinta** —en azul, como una fecha cargada a mano— y el tooltip dice el vencimiento original de Tango y quién lo cambió. Sin permiso se ve sólo la fecha; sin el script, el input se dibuja apagado nombrando `sql/cashflow_tarjetas_vto_mensual.sql`.

**Si Tango cambia el vencimiento de esa cuota**, la corrección queda sin cuota a la que aplicarse: **queda inerte** —no se reapunta sola, porque el vencimiento nuevo de Tango puede ser justamente la corrección— y **se avisa con los comprobantes**, con una lista bajo la grilla para darla de baja. **Sólo se avisa si el comprobante sigue pendiente**: una corrección de un comprobante que ya se pagó no corrige nada, y avisarla haría crecer el aviso para siempre.

### Facturas mensuales: la estimación de los abonos

Para las facturas que se repiten todos los meses: **marcarla como mensual genera una estimación de los meses siguientes.** Se marca con el ícono de repetir de la columna *ESTADO*.

> **Va en la fila y no en la barra de selección**, y es a propósito: la barra actúa sobre varias facturas a la vez, y marcar mensual es de **una** —hay una sola estimación vigente por proveedor, así que marcar dos del mismo proveedor en un lote sería contradictorio—.

**Las reglas** (`TarjetasCorporativas::datosMarcaMensual()` y `estimaciones()`, puras):

- **Sólo una factura vinculada a una tarjeta y no excluida.** Lo valida el backend.
- Se guarda una **copia** en `RO_T_CASHFLOW_TARJETAS_MENSUAL`: proveedor y razón social, el comprobante de origen con su vencimiento, la tarjeta, el importe, el día del mes y el primer mes. Existe porque la factura sale de pendientes cuando se paga, y la estimación tiene que seguir viva.
- **El importe es el de la cuota marcada, antes de imputaciones (`IMPORTE_VTO`), no el pendiente**: el pendiente puede estar pagado en parte, y lo que se repite es lo que se debita por mes. En una factura de una cuota coincide con el total; en una en cuotas, el total del comprobante multiplicaría el importe por la cantidad de cuotas. **Fijo, sin inflación.**
- **Desde el mes siguiente** al del vencimiento vigente —el corregido si lo hay— **hasta el fin del horizonte**, sin fecha de fin: se corta desmarcando.
- **El día** es el del vencimiento vigente, **acotado al último día** en los meses que no lo tienen, y **no se corre al hábil**: ese día ya es el vencimiento del resumen.
- **Una sola vigente por proveedor**: marcar otra factura del mismo proveedor da de baja la anterior, con confirmación. El índice único filtrado es la red.
- **Se apaga sola por mes**: si en el universo de esta pestaña hay una factura real **del mismo proveedor** con vencimiento vigente en ese mes —cualquiera, vinculada o no, excluida o cubierta—, ese mes no proyecta y la grilla dice *"reemplazada por FAC …"*, aunque esa factura no sume.
- **Una estimación con fecha ≤ hoy no se proyecta.**
- **Se comporta como una factura vinculada**: genera cobertura con el % de su tarjeta en su mes, y si ese mes tiene resumen cargado queda cubierta y no suma.
- **Si su tarjeta está inactiva, ya no existe o dejó de ser corporativa**, no proyecta y se avisa.
- **Excluir la factura de origen no toca la estimación**: son decisiones distintas, y la estimación se desmarca explícitamente.

**En pantalla**, una sección propia, **Estimaciones mensuales**, separada de la grilla por el mismo motivo que *Cobertura y resúmenes* —no son facturas—: proveedor, comprobante de origen, tarjeta, importe, día, el estado de cada mes (*entra*, *reemplazada por …*, *cubierta por resumen*, *ya pasó*, *sin tarjeta activa*), las columnas del eje con las mismas vistas y su pie, y quién la marcó. Se desmarca desde ahí, **también cuando la factura de origen ya se pagó**. El KPI *Entra al flujo* las incluye y dice cuánto es estimación.


### La exclusión saca de la serie, no del universo

Mismo patrón que Proveedores Locales: selección múltiple, **un motivo obligatorio** validado en el backend, y **una sola transacción**. Tabla propia con historial (`VIGENTE`, `FECHA_BAJA`, `USUARIO_BAJA`), **sin bajas físicas**.

**Es de esta pestaña y no toca la de Proveedores Locales.** Son dos decisiones distintas sobre la misma factura:

```
RO_T_CASHFLOW_PROV_LOCALES_PAGO.EXCLUIDA   "no entra a Cuentas a Pagar Locales"
RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA    "no entra a Pagos con Tarjetas y Otros"
```

Compartir la tabla obligaría a que excluir de una excluyera de la otra, que es exactamente lo que no se quiere: estas facturas viven en las dos, en series que no conviven en el tablero.

- **Interruptor *Ver excluidas*, apagado por defecto.** Al prenderlo se ven atenuadas y con el importe tachado. **Cuántas son y por cuánto se dice siempre**, también cuando están escondidas: una exclusión puesta en marzo que nadie recuerda es lo que ese aviso evita.
- **Las excluidas no suman a la fila del tablero**: van a `CORPORATIVAS_EXCLUIDAS`, que es informativa.
- **Las que ya estaban excluidas se saltean sin pisar su motivo.** Lanzar por una que ya estaba abortaría las otras siete del lote, y pisar el motivo borraría el porqué de la decisión vigente.
- **La exclusión gana sobre la cobertura por resumen**: una excluida *y* cubierta se contaría en la serie informativa *y* adentro del resumen.

### El doble conteo con los comprobantes de tarjeta de supervisoras

Los gastos con tarjeta de las supervisoras se estiman en *Gastos Supervisoras* a partir de `RO_T_GASTOS_SUPERVISION`, y **los comprobantes de esos mismos gastos pueden estar cargados en Tango** como facturas de un proveedor con forma de pago `TARJETA CORP`. Cuando eso pasa, el mismo peso entra dos veces.

**No se resuelve por código, y es deliberado**: decidir cuál de las dos puntas es la buena para un comprobante concreto es mirar el comprobante, no aplicar una regla. Se maneja **excluyendo esas facturas** con la tabla de exclusión, y **se evalúa en producción**.

### La clave es el comprobante, y eso tiene una consecuencia

`(COD_PROVEE, T_COMP, N_COMP)`, idéntica a `Proveedores::clavePago()`. **No incluye el vencimiento.** La tarjeta con la que se paga una factura es una propiedad de *la factura*, no de cada cuota, y agregar la fecha habilitaría vincular la cuota 3 a una tarjeta y la 4 a otra, que no describe nada real. Además, las cuatro tablas del circuito y la de Proveedores Locales comparten una sola clave.

Un comprobante con varias cuotas queda vinculado entero y cada cuota genera su cobertura en el mes de **su** vencimiento. En este universo, los comprobantes en cuotas son los seguros de OGRSA —6 comprobantes con entre 6 y 10 cuotas—, que no son los que se vinculan a una tarjeta.

---

## 5. Tarjetas Socios

### La base son los últimos tres resúmenes, de cualquier origen

Al principio se cargan tres de una con **Cargar base histórica** (`ORIGEN = 'HISTORICO'`, ya pagados). Después, los que se van cargando mes a mes (`ORIGEN = 'CARGA'`) pasan a formar parte del histórico y **la base se corre sola**.

**`ORIGEN` no participa del cálculo**: un resumen es un resumen, y de dónde salió importa para poder explicar por qué hay tres cargados el mismo día, no para decidir si cuenta.

Se toman los de **período anterior al mes en curso**: el del mes en curso puede estar cargado y todavía no vencido, y usarlo como base sería estimar el mes con su propio dato.

### Se divide por los resúmenes que hay, no por tres

Y es la regla **contraria** a Gastos Supervisoras. No es una inconsistencia: son dos cosas distintas.

| | La base es | Un elemento que falta es | Y entonces |
| --- | --- | --- | --- |
| **Supervisoras** | Tres **meses calendario**, y los tres existen | Un mes en el que **no se gastó**: es un dato | Se divide por **3** |
| **Socios** | Los últimos tres **resúmenes** | Un resumen que **no se cargó** | Se divide por **los que hay** |

Dividir por tres con dos resúmenes afirmaría que ese mes no hubo gastos —algo que nadie dijo— y proyectaría de menos. Con menos de tres se promedia lo que hay **y se avisa**. Sin ninguno: `null` y el aviso dice *"cargá la base histórica"*.

> **Un resumen sin importe en una moneda sí cuenta como cero en esa moneda.** El resumen existe y no tuvo consumos en dólares ese mes: eso es un dato, y es distinto de que el resumen falte.

### La inflación ajusta sólo el componente en pesos

**Es la decisión más importante de esta sub-pestaña.** El componente en `U$S` se convierte con dólar futuro, y **la curva ya incorpora la devaluación esperada**: ajustarlo además por inflación contaría dos veces el mismo efecto.

No es un matiz. Con los números del 26/09/2026:

| | Variación sep-26 → ago-27 |
| --- | --- |
| Curva de dólar futuro (1521,5 → 1857,5) | **+22,1 %** |
| Inflación cargada, 2 % mensual compuesto | **+24,0 %** |
| Las dos juntas | **+51,4 %** |

El componente en pesos no tiene esa corrección por ningún lado, así que **lo ajusta la inflación**, con la misma función `Inflacion::compuesta()` que usa Gastos Supervisoras. El mes base es el del resumen más reciente de los tres.

### Las dos reglas del dólar

| | Con qué se convierte | Por qué |
| --- | --- | --- |
| **El próximo vencimiento** | **Dólar oficial BCRA**, última cotización conocida, punta compradora | Es un pago inminente: se liquida al tipo de cambio actual, y la curva de futuros del mes en curso ya incorpora expectativa que para dentro de dos semanas no se cumple |
| **Los siguientes** | **Dólar futuro** del mes de su vencimiento | Ahí sí hay tiempo para que la devaluación ocurra, y valuar a dólar de hoy proyectaría de menos |

Con un solo criterio, o se proyecta de menos a doce meses o se infla un pago de dos semanas.

**El próximo vencimiento lo resuelve la misma función que usa Pagos Corporativos** (`TarjetasVencimiento::proximoPago()`), que mira resúmenes *y* estimaciones: un resumen cargado del mes en curso que vence en tres días **es** el próximo pago, y saltearlo correría la regla del BCRA un mes entero.

**La misma regla vale para un resumen real cargado**: la pregunta —a cuánto se va a liquidar— no depende de si el importe es estimado o real. Lo que el resumen **no** lleva es cobertura ni ajuste: es lo que el banco va a debitar.

### Siempre se dice qué dólar se usó

Con su fecha y su punta si es el BCRA, o con el mes de la curva y si es **aproximado** si es futuro. Va en el tooltip de la celda, y la regla completa en el cartel de la pestaña. Un importe en pesos que salió de una conversión y no dice con qué se convirtió **no se puede verificar contra nada**.

**Sin cotización: `null` y aviso, nunca cero.** Y el **total no suma un `null` como cero**: con el componente en pesos sin resolver, un total que sólo trae los dólares es más chico que el real y no lo dice. El de dólares se calcula y se informa igual, porque es correcto y lo que falta es la otra mitad.

> Una tarjeta **sin consumos en dólares no pide ninguna cotización**: no tiene por qué quedar sin proyectar porque falte la curva.

### En pantalla

Por tarjeta, cuatro renglones: **total en pesos**, **consumos en `$`**, **`U$S` equivalente en `$`** y **`U$S`** (informativa, en itálica y gris, porque no suma en pesos: un importe en dólares alineado debajo de tres en pesos se suma con la vista sin que nadie se dé cuenta).

---

## 6. El vencimiento de una tarjeta, y por qué corre para el otro lado

El día del mes que tiene cargado la tarjeta, **acotado al último día si el mes no lo tiene**, y después **corrido al primer día hábil siguiente** si no es hábil.

**El orden importa**: acotar primero y correr después es lo único que hace que un 31 de febrero termine en el 28 —o en el 2 de marzo si el 28 es sábado— y no en una fecha que sale de un día que no existe.

### Las dos direcciones del módulo

| | Dirección | Por qué |
| --- | --- | --- |
| **`CronogramaPagos`** — pago a un fletero, efectivo de una supervisora | Al día hábil **ANTERIOR** | El compromiso es con una persona que cobra esa semana |
| **`TarjetasVencimiento`** — débito de una tarjeta | Al día hábil **SIGUIENTE** | El banco no puede debitar un día que no opera |
| **`Ventas`** — acreditación de una cobranza | Al **próximo** día hábil | El banco no acredita antes de poder hacerlo |
| **`Saldos`** — acreditación de la caja de un local | Al **próximo** día hábil | Ídem: es una acreditación (ver `README-saldos.md`) |

**Las reglas viven en clases distintas, y la mecánica de ir hacia adelante es una sola.** El encabezado de `CronogramaPagos` dice explícitamente que las direcciones opuestas no se mezclan, y agregarle la de adelante lo convertiría en un cajón de fechas de pago en vez de una regla. Por eso `habilAnterior()` sigue en `CronogramaPagos`. Las tres que van hacia adelante eran dos copias letra por letra, y la caja de los locales iba a ser la tercera. Desde `feature/saldos-locales-dia-acreditacion` llaman todas a `DiasHabiles::siguiente()`, que tiene el corrimiento, el respaldo de lunes a viernes y el texto del aviso.

**Lo que se comparte es la mecánica, no la decisión.** Cada regla elige su dirección según la función que llama: si mañana el débito de una tarjeta pasa a adelantarse, `TarjetasVencimiento` deja de llamar a `siguiente()` y Ventas no se entera. Y cada una decide qué hacer si el mapa no tiene ningún hábil en 30 días. `DiasHabiles` no lanza: devuelve la fecha del tope con `sin_habil`. Ante eso:
- Tarjetas lanza, como siempre;
- Ventas sigue con aviso, porque su pantalla no se cae;
- Saldos deja la fecha en `null` y avisa.

**Lo que sí se comparte es el calendario**, que es lo que no puede estar escrito dos veces: `Ventas::getDiasHabiles()` es la única lectura de `RO_T_CALENDARIO` del módulo, y se llega por `CronogramaDatos::habilesEntre()`. Lo que cambia es el **rango**: el cronograma pide un mes *antes* del eje y el vencimiento pide 30 días *después* de `fin()`, porque el corrimiento de un día 31 del último mes puede terminar en el mes siguiente. Dos rangos sobre la misma consulta no son dos definiciones de *"día hábil"*; dos consultas sí.

**Sólo se corre la estimación, nunca un resumen cargado.** La fecha de un resumen se tipea leyéndola del papel: es un hecho, y correrla sería contradecir a quien la leyó.

---

## 7. El tablero

`TarjetasProvider` → una sola fila, **Pagos con Tarjetas y Otros**, en *Costos Indirectos*, orden **45** (entre Impuestos en 40 y el subtotal en 50), apuntada a `TARJETAS` / `TOTAL`.

### Cinco series, y la fila usa una

| Serie | Qué trae |
| --- | --- |
| `TOTAL` | La suma de las tres partes. **Es la que usa la fila** |
| `SUPERVISORAS` | Efectivo + tarjeta de los gastos de supervisión |
| `CORPORATIVAS` | Facturas **vinculadas** no excluidas + estimaciones mensuales + cobertura, o el resumen donde lo haya |
| `SOCIOS` | En pesos, con el componente `U$S` ya convertido |
| `CORPORATIVAS_EXCLUIDAS` | Sólo informativa, **fuera del total** |

**El total se arma sumando las tres series, columna por columna**, y no reagrupando los pagos otra vez: sumar las series es la única forma de que el invariante no pueda romperse por un pago que se cuente en una y no en el otro. La prueba lo verifica columna por columna.

**El total y sus partes no pueden convivir.** El registro lo declara en `componentes`, así que si alguien activa una fila con `SUPERVISORAS` al lado de la del total, el validador de *Parámetros › Cashflow* lo rechaza. Para partir la fila en sus tres partes hay que **inhabilitar la del total y activar las tres** — sin tocar código.

**`CORPORATIVAS_EXCLUIDAS` no es componente del total**, y por eso **sí** puede convivir con la fila del total: su importe no está en el total, justamente porque se decidió que no entre.

### No hay serie de universo, y es deliberado

Echeqs declara `A_COBRAR_TODO` y Proveedores Locales `PAGOS_TODO`: el universo contra el que el validador mide el doble conteo. **Acá no se puede declarar uno honesto**, porque `CORPORATIVAS` no es sólo facturas: también lleva la cobertura, y puede quedar reemplazada por el resumen. Un `CORPORATIVAS_TODO` sería la suma de cosas de distinta naturaleza —deuda real, un porcentaje estimado y un resumen que reemplaza a los dos— y no la partición de nada.

Lo que sí se mantiene es el invariante que importa, y lo fija la prueba:

```
TOTAL = SUPERVISORAS + CORPORATIVAS + SOCIOS     (columna por columna)
```

### El detalle, y no una serie aparte

Un resumen cargado y la estimación que reemplaza caen casi siempre en la **misma columna** —entre las dos fechas hay unos cinco días—, así que van en la misma fila y la celda queda anotada con `detalle`, la anotación de celda que ya existe en el contrato. Una serie aparte sería una fila nueva sumando un importe que la fila original ya suma: doble conteo.

**Dos resúmenes que caen en la misma columna juntan sus notas**: el contrato admite una anotación por celda, y quedarse con la primera escondería la segunda.

**Las estimaciones mensuales también se anotan**, con el proveedor y el mes: entran en `CORPORATIVAS` —y por eso en `TOTAL`— como una factura vinculada, pero no son deuda de Tango, y quien mira la celda tiene que poder saberlo. El invariante `TOTAL = SUPERVISORAS + CORPORATIVAS + SOCIOS` se sigue cumpliendo columna por columna, y la prueba lo fija con estimaciones adentro.

### Ojo con el doble conteo contra Proveedores Locales

Las facturas de tarjeta corporativa **también** están en el universo de Proveedores Locales, en su serie `PAGOS_FUERA_CRONOGRAMA` —`TARJETA CORP` no es una forma del cronograma—.

**Con la configuración de hoy no hay doble conteo**: la fila de *Cuentas a Pagar Locales* usa la serie `PAGOS`, que deja esas facturas afuera. Verificado contra la base el 26/09/2026.

**Pero** si alguien reapunta esa fila a `PAGOS_TODO` o a `PAGOS_FUERA_CRONOGRAMA`, o activa una fila nueva contra cualquiera de las dos, **esas facturas se cuentan dos veces** y el cuadro cierra igual, así que nada lo delata.

> **El validador de estructura no lo puede ver**, y por eso no se intentó: son dos proveedores distintos y el solapamiento es de **datos** —las mismas facturas de Tango—, no de series. Acoplar los dos proveedores para detectarlo rompería justamente lo que hace que agregar un módulo no toque el motor. En su lugar hay dos avisos: el control al pie de `sql/cashflow_tarjetas_fila.sql`, que lo verifica al correr el script, y el de `ProveedoresProvider`, que separa lo que **sí** entra al cuadro de lo que no entra por ninguna fila. Desde `feature/cronogramas-tarjetas-corporativas`, de `TARJETA CORP` sólo entran **las vinculadas a una tarjeta**, y el aviso lo dice con importes: lee los vínculos factura-tarjeta, y si no puede, queda el texto sin importes y un aviso.

### Una fila en cero siempre se explica

Un egreso en cero se lee como *"no hay que pagar nada"*, que es lo contrario de lo que pasa. Los avisos dicen **qué** falta; el del cero dice la **consecuencia** sobre el cuadro, que es lo que se ve en el tablero.

---

## 8. Lo que este módulo avisa, y por qué

Los avisos son la mitad de lo que esta pestaña hace: al 26/09/2026, con las tablas recién creadas, **queda afuera más plata de la que entra**.

| Aviso | Hoy |
| --- | --- |
| Supervisoras **sin tarjeta cargada**: su parte tarjeta no entra | 6 supervisoras, **$ 60.118.164,98** |
| Supervisoras activas **sin gastos en la ventana** | JULIETA DALMEIDA |
| Facturas **sin vincular**: no entran al flujo, con el desglose de vencidas y no vencidas | Al 26/09/2026 eran los 135 vencimientos: **$ 89.896.563,43** |
| Facturas **excluidas** a mano, con sus motivos | — |
| Vencimientos **corregidos que ya no se aplican**: Tango cambió el vencimiento de la cuota | — |
| Estimaciones mensuales cuya **tarjeta está inactiva** o ya no existe | — |
| Meses **sin inflación** cargada, diciendo si están fuera de la ventana editable | — |
| Meses **sin cotización** para valuar el componente en dólares | — |
| Tarjetas de socio **sin base histórica** | — |

**Cada aviso describe un hecho distinto y va aparte.** Juntarlos haría que el importe total no se pudiera atribuir a ninguna causa, que es justamente lo que un aviso tiene que permitir.

---

## 9. Permisos

La pestaña pasa por `AuthCashflow::puede('pagos_tarjetas')`, que busca la clave **`cashflow.tab.pagos_tarjetas`** en `FP_PERMISOS` (módulo 7). `TabController` la vuelve a chequear antes de servir el archivo.

**No se agregó ningún permiso nuevo**, ni para cargar resúmenes ni para vincular o excluir facturas: se usan los que ya tenía `pagos_tarjetas`. Ese permiso vive en el módulo de Gestión de Usuarios, que es otro repo, así que darlo es un paso manual del deploy. Mientras no exista, el ítem **no aparece en el menú** y la pestaña responde 403: falla cerrada.

**Las sub-pestañas de Parámetros no tienen permiso propio**, y vale tenerlo presente: **dar acceso a Parámetros ahora incluye el maestro de tarjetas**, que decide en qué fecha cae buena parte de esta fila.

---

## 10. La auditoría de este módulo graba al usuario de verdad

Este módulo fue el primero con auditoría de verdad, y fue el modelo del resto: desde `feature/cashflow-auditoria-usuario` **todo el módulo** usa su esquema y lo escribe igual. El usuario sale sólo del padrón de Gestionusuarios, por la guarda común de los controllers (`Controller/autorizacion.php`): se fue `TarjetasController::usuarioActual()`, y con él la vuelta a `$_SESSION['usuario']`, que firmaba con un nombre al que nunca se le preguntó si podía. Sin usuario del padrón la escritura se rechaza (401), y sin `cashflow.editar.pagos_tarjetas` —o `cashflow.editar.parametros.tarjetas`, para el maestro— también (403).

El maestro `RO_T_CASHFLOW_TARJETAS` recibió además `USUARIO_BAJA` / `FECHA_BAJA` para su `ACTIVA`, y reactivar una tarjeta las limpia. Ver *Auditoría y permisos de escritura* en `README-cashflow.md`.

---

## Lo que este módulo no hace

- **No resuelve el doble conteo con los comprobantes de tarjeta de supervisoras cargados en Tango.** Se maneja excluyendo facturas y se evalúa en producción. Ver la sección 4.
- **No excluye a nadie de Proveedores Locales.** La exclusión de esta pestaña es sólo de esta pestaña.
- **No crea la vista de usuarios.** Existe y se mantiene aparte.
- **No agrega permisos nuevos.**
- **No parte la fila del tablero.** Las series de cada parte quedan declaradas para poder hacerlo desde Parámetros el día que se quiera.
- **No convierte el componente en dólares de una tarjeta corporativa.** Las facturas de ese circuito son en pesos; un resumen corporativo con importe en dólares se informa y no se convierte.

---

## Pruebas

```
php cashflow/tests/run.php tarjetas      los cinco archivos del módulo
```

| Archivo | Qué fija |
| --- | --- |
| `tests/test_tarjetas_vencimiento.php` | El día del mes en **meses cortos** y en **año bisiesto**, el corrimiento al hábil **siguiente** —contrastado contra el **anterior** de `CronogramaPagos`—, fin de semana, **feriado encadenado**, cruce de mes y de año, el fallback de calendario con su aviso, el tope defensivo, el próximo vencimiento posterior a hoy —con el que cae hoy quedando afuera—, los **dos rangos de calendario opuestos**, y la **inflación compuesta** con su mes faltante, comparada contra `acumulada()` sobre el mismo trimestre |
| `tests/test_tarjetas_maestro.php` | Los tres tipos **contrastados contra el `CHECK` del script**, los últimos 4 con su **cero de adelante**, el % con su tope, los importes del resumen —al menos uno, ninguno en cero ni negativo—, el aviso cuando el período y la fecha no coinciden, el rótulo único, la declaración del módulo de Parámetros con su posición y su endpoint, y que los tres scripts sean **reejecutables, no borren nada y no creen la vista** |
| `tests/test_tarjetas_supervisoras.php` | El efectivo en **los lunes del mes** —quintos en un mes de cinco, cuartos en uno de cuatro, sin cambiar la parte cuando los primeros ya pasaron—; la **ventana dinámica** —incluido el cruce de año y que no se mueva dentro del mes—, que **siempre se divida por 3**, la proporción de la ventana, la inflación compuesta con un mes faltante, los cuatro motivos para no proyectar, el **reparto en 2 pagos** con el que cae hoy y el que ya pasó, que el **resumen pise importe y fecha**, que un **resumen pagado salga del horizonte**, y que sin tarjeta no se invente una fecha |
| `tests/test_tarjetas_corporativas.php` | Que **una no vinculada no suma**, vencida o no, y que no haya aviso de doble conteo; el **vencimiento corregido** reemplazando al de Tango en vencida, reubicación, cobertura y resumen, que deshacer vuelva al de Tango, que sea **por cuota**, la **fecha mínima hoy** y la **corrección inerte** con su aviso; la **estimación mensual** desde el mes siguiente, con el **día acotado** en meses cortos y sin correr al hábil, **reemplazada por la factura real** del mismo proveedor y mes, **cubierta por resumen**, con **cobertura**, sin tarjeta activa, con la factura de origen ya pagada y **una por proveedor**; y que **lea el `FECHA_VTO` de Tango** y no la fecha que reubica el cronograma de Proveedores Locales. Además: el universo por forma vigente, que **lo vencido no se apile en el día uno**, la reubicación al próximo pago, que una vencida sin vincular no proyecte, que la **exclusión saque de la serie y no del universo**, la **cobertura sobre las vinculadas** —y no sobre las que no suman—, que el **resumen reemplace facturas y cobertura**, que las **no vinculadas sigan sumando con aviso**, y el próximo pago mirando resúmenes y estimaciones |
| `tests/test_tarjetas_socios.php` | La base de 3 resúmenes y que **se divida por los que hay**, que la **inflación no toque el componente en U$S**, la **regla BCRA para el próximo y futuro para los siguientes**, que un resumen del mes en curso sea el próximo pago, que un pagado salga del horizonte, que sin cotización quede en `null`, y que el **total no sume un `null` como cero** |
| `tests/test_tarjetas_provider.php` | **`TOTAL = SUPERVISORAS + CORPORATIVAS + SOCIOS` columna por columna**, también con **estimaciones mensuales** adentro y su celda anotada, que las excluidas no entren al total, los importes en positivo, que el eje ubique cada pago, el `detalle` de las celdas con resumen —y que **sobreviva a `normalizar()`**—, que dos resúmenes en la misma columna **junten sus notas**, los casos de fila en cero **con su aviso**, y que un módulo que lanza **no tumbe el tablero** |
| `tests/test_menu.php` | Que `pagos_tarjetas` **dejó de ser un placeholder**, que el menú la declara con datos, que las otras dos de Financiero siguen pendientes y que la categoría pasa a **1 de 3** |
| `tests/test_providers.php` | **19 módulos** con datos reales (eran 18), que `FINANCIERO` **no se tocó** y que el total declara sus tres componentes |
| `tests/test_cargando.php` | **28 lugares** del indicador (eran 27) |

### Dos cosas que las pruebas y el trabajo contra la base destaparon

1. **`Tarjetas::getTarjetas()` pedía los bancos y los usuarios entre el `sqlsrv_query` y el `fetch`.** El driver usa **pool de conexiones**, así que `Conexion::conectar()` puede devolver la misma conexión física que ya tiene ese statement pendiente: la segunda consulta lo invalida y el fetch falla con *"supplied resource is not a valid ss_sqlsrv_stmt resource"*. El síntoma es de los peores, porque aparece sólo cuando la segunda consulta existe: un método que funcionaba se rompe al agregarle una lectura. Queda documentado en el encabezado de la clase.

2. **Dos pruebas de `test_proveedores.php` venían fallando en `develop`**, y ninguna era un bug del código: la del ancho del pie hacía mal la cuenta —el pie tiene dos celdas sueltas y contaba una— y la del filtro comparaba contra la forma del maestro en vez de contra la **vigente**, así que cada factura con override daba una discrepancia legítima. Se arreglaron en dos commits aparte, antes del desarrollo.

---

## Archivos

```
Class/
  TarjetasVencimiento.php      el día de vencimiento y su corrimiento. PURO
  TarjetasSupervisoras.php     ventana, promedio, proporción y reparto. PURO
  TarjetasCorporativas.php     universo, reubicación, cobertura y resumen. PURO
  TarjetasSocios.php           base, ajuste y las dos reglas del dólar. PURO
  Tarjetas.php                 el maestro de tarjetas
  TarjetasResumen.php          los resúmenes y la base histórica
  TarjetasFactura.php          el vínculo factura-tarjeta
  TarjetasExclusion.php        la exclusión por factura, de esta pestaña
  TarjetasFacturaVto.php       el vencimiento corregido de una cuota
  TarjetasMensual.php          las facturas marcadas como mensuales
  GastosSupervision.php        los gastos autorizados y el maestro de supervisoras
  PagosTarjetas.php            el armador: lee una vez y arma las tres sub-pestañas
  Inflacion.php                + compuesta(), compuestaParaMeses() y avisoFaltan()
  CronogramaDatos.php          + habilesEntre() público
  Providers/
    TarjetasProvider.php       TARJETAS / TOTAL y las cuatro series más

Controller/
  TarjetasController.php       las cuatro pantallas del circuito

Tabs/
  pagos_tarjetas.php           la pestaña (era un placeholder)
  parametros_tarjetas.php      sub-pestaña Parámetros › Tarjetas
  parametros.php               + el pane nuevo

Js/
  Financiero-Pagos_tarjetas.js
  Parametros-Tarjetas.js

Css/
  Financiero-Pagos_tarjetas.css

sql/
  cashflow_tarjetas.sql
  cashflow_tarjetas_facturas.sql
  cashflow_tarjetas_fila.sql
  cashflow_tarjetas_vto_mensual.sql

tests/
  test_tarjetas_vencimiento.php
  test_tarjetas_maestro.php
  test_tarjetas_supervisoras.php
  test_tarjetas_corporativas.php
  test_tarjetas_socios.php
  test_tarjetas_provider.php
```

---

## Pendientes conocidos

- **El permiso `cashflow.tab.pagos_tarjetas` hay que darlo a mano** en Gestión de Usuarios. Este repo no siembra permisos para ninguna pestaña.
- **No hay ninguna tarjeta cargada todavía.** Hasta que las haya, la pestaña avisa y **la parte de Corporativas de la fila del tablero va en cero**: sin tarjeta, ninguna factura entra al flujo.
- **Una corrección de vencimiento de un comprobante que ya se pagó queda vigente y no se avisa.** No aplica a nada; avisarla haría crecer el aviso para siempre. Si molesta en el historial, se depura aparte.
- **Una estimación mensual no tiene fecha de fin.** Se corta desmarcándola; si un abono se da de baja con el proveedor, alguien tiene que acordarse de desmarcarlo.
- **El doble conteo con los comprobantes de tarjeta de supervisoras se evalúa en producción**, excluyendo facturas.
- **La inflación de un mes anterior a la ventana editable no se puede cargar.** La grilla de *Parámetros › Generales* arranca dos meses antes del mes en curso, así que una tarjeta de socio cuyo resumen más nuevo sea más viejo que eso queda sin proyectar, avisando un mes que no tiene dónde tipearse. No se tocó `Inflacion::MESES_ATRAS`: ensancharlo es una decisión sobre una pantalla que usan todos los módulos.
- **El resto del módulo sigue grabando `NULL` en la auditoría.** Ver la sección 10.
- **Un resumen de una tarjeta corporativa con importe en dólares se informa y no se convierte.** Ese circuito es en pesos; si alguna vez hace falta, es una decisión y no un arreglo.
