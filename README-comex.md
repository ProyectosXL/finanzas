# Módulo Comercio Exterior — los pagos al exterior y la nacionalización

Pestañas **Comercio Exterior → Proveedores Exterior** y **Crono Nacionalización**, y las filas *Proveedores del Exterior* y *Nacionalizaciones* del tablero de Cashflow.

Ramas: `feature/comex-fecha-maestra` · `feature/comex-pagado` · `feature/comex-nac-usd` · `feature/comex-saldo-pendiente`

---

## La idea en una línea

**Las dos pestañas son el mismo contenedor mirado desde los dos lados del circuito**, y las dos escriben sobre el mismo lugar. Qué se escribe dónde es la decisión de fondo de todo esto: **la fecha va al maestro de Comex porque es el mismo dato para las dos aplicaciones; el "ya se pagó" queda del lado del cashflow porque es una afirmación sobre su propia proyección.**

```
RO_T_IMPORTACIONES_ENCABEZADO  (maestro de Comercio Exterior)
        │  sin detalle cargado: el contenedor todavía no cerró
        │
        ├─ FECHA_EST_PAGO ──── cuándo se le paga al proveedor del exterior
        │       │
        │       │  VALOR_FOB_DOLAR de la OC principal
        │       │    − RO_T_IMPORTACIONES_ENCABEZADO_PAGOS  (lo ya pagado, en U$S)
        │       │    = lo que FALTA pagar, que es lo único que se proyecta
        │       │  × curva de dólar futuro ROFEX del mes de esa fecha
        │       ▼
        │   Proveedores Exterior  →  ComexProvider
        │                            (PAGOS / PAGOS_PAGADOS / PAGOS_COMEX / PAGOS_TODO)
        │
        └─ FECHA_DESP_ADU ──── cuándo se nacionaliza
                │  + RO_T_IMPORTACIONES_ESTIMACION_DETALLE (conceptos 3 a 10,
                │    que TAMBIÉN están en dólares: ver la sección 6)
                │  × curva de dólar futuro ROFEX del mes de esa fecha
                ▼
            Crono Nacionalización  →  ComexProvider (NACIONALIZACION / _PAGADAS / _TODO)

Del lado del cashflow:
  RO_T_CASHFLOW_COMEX_FECHA_EDIT   quién movió cada fecha del maestro desde acá
  RO_T_CASHFLOW_COMEX_PAGADO       qué pagos ya se hicieron, con su historial
  RO_T_CASHFLOW_COMEX_CRONO_NAC    el override de cotización por contenedor
```

**De las tres tablas de Comercio Exterior que el cashflow lee, la de pagos es la única que no estaba** hasta `feature/comex-saldo-pendiente` — y es la única cuya ausencia **cambia números** en vez de apagar un botón. Ver la sección 8. El cashflow la lee y **nunca la escribe**: los pagos se cargan en Comercio Exterior.

Al cuadro entra **lo que falta mover, de hoy en adelante**: lo vencido y lo ya pagado quedan afuera, y las dos cosas se ven en la pestaña detrás de su interruptor.

---

## Ejecución de los scripts

Contra `central`, en este orden:

| # | Script | Qué hace | Si no se corre |
| --- | --- | --- | --- |
| 1 | `sql/cashflow_comex_cotiz_edit.sql` | Agrega `COTIZ_USD_EDIT` a `RO_T_CASHFLOW_COMEX_CRONO_NAC`: el override de cotización por contenedor | Los pagos se valúan con la curva igual; lo único que no se puede es corregir una fila a mano, y la pantalla lo dice |
| 2 | `sql/cashflow_comex_fecha_maestra.sql` | Crea `RO_T_CASHFLOW_COMEX_FECHA_EDIT` y **migra al maestro** las fechas que vivían en las columnas `EDIT` | **Las dos pestañas se leen igual** —las fechas salen del maestro, que siempre está— pero **no se pueden editar**, y las dos avisan qué script falta |
| 3 | `sql/cashflow_comex_pagado.sql` | Crea `RO_T_CASHFLOW_COMEX_PAGADO`: qué pagos ya se hicieron, con su historial | Las dos pestañas se leen igual y **el tablero no cambia** —sin la tabla no hay nada marcado, así que proyecta todo lo pendiente—. La casilla se dibuja deshabilitada y las dos avisan qué script falta |

Los tres son reejecutables y ninguno borra nada. El 3 no depende de los otros dos.

> **El 2 es el único del módulo que escribe sobre una tabla que no es del cashflow.** Por eso su encabezado documenta el criterio de conflicto y el script lista al final lo que decidió no migrar.

### Qué hizo la migración en la base real

Verificado contra la base el **19/09/2026**, antes y después de correrlo:

| | |
| --- | --- |
| Filas en `RO_T_CASHFLOW_COMEX_CRONO_NAC` | 7 |
| Ediciones a migrar (cada fila puede tener las dos fechas) | 9 |
| Huérfanas — el contenedor ya no está en el maestro | **3** (las dos del `ID_MG` 558 y la de nacionalización del 560) |
| Ya coincidían con el maestro | **6** |
| En conflicto | **0** |
| Sobre un maestro vacío | **0** |
| **Fechas escritas en el maestro** | **0** |
| Rastros registrados | 6 |

O sea: **la migración no cambió ni una fecha**. Lo único que dejó es el rastro de quién las había editado, que hasta ahora no se guardaba en ningún lado. Lo que **sí** mueve el tablero es otra cosa, y está más abajo: el filtro de embarque que esta rama saca.

---

## 1. Una sola fecha, en el campo maestro

### Qué había antes

Las fechas editadas desde el cashflow se guardaban en `RO_T_CASHFLOW_COMEX_CRONO_NAC`, en cuatro columnas: `FECHA_PAGO_ORIG` / `FECHA_PAGO_EDIT` y `FECHA_NAC_ORIG` / `FECHA_NAC_EDIT`. La fecha efectiva salía de un `COALESCE(editada, la del maestro)`.

El resultado era **un dato partido en dos**: la app de Comercio Exterior mostraba una fecha y el cashflow otra, las dos vigentes, y ninguna pantalla decía que existía la otra. Quien movía un pago desde el cashflow lo movía **sólo para el cashflow**.

### Qué hay ahora

El cashflow escribe **directo sobre el maestro**:

| Se edita | Va a |
| --- | --- |
| Fecha estimada de pago | `RO_T_IMPORTACIONES_ENCABEZADO.FECHA_EST_PAGO` |
| Fecha estimada de nacionalización | `RO_T_IMPORTACIONES_ENCABEZADO.FECHA_DESP_ADU` |

Hay **un solo lugar por fecha**, y las dos apps lo leen. La notificación lo dice al guardar: *"Fecha actualizada en el maestro de Comercio Exterior: la ve también esa aplicación."* Quien mueve la fecha tiene que saber que la está moviendo del otro lado.

**Las columnas `EDIT` quedan** —este módulo no borra nada— pero **nadie las lee**. `RO_T_CASHFLOW_COMEX_CRONO_NAC` sigue viva por `COTIZ_USD_EDIT`, que es otro circuito y no se tocó.

### El rastro, y por qué va del lado del cashflow

El maestro es de la plataforma Comex y **no tiene columnas de auditoría**: no hay dónde decir que esa fecha la movió alguien desde el tablero de fondos. Sin eso, tres meses después una fecha corrida a mano desde el cashflow y una calculada por la app de Comex son indistinguibles.

`RO_T_CASHFLOW_COMEX_FECHA_EDIT` es ese rastro: `ID_MG`, `CAMPO` (`PAGO` / `NAC`), `FECHA_ANTERIOR`, `FECHA_NUEVA`, `VIGENTE`, `USUARIO`, `FECHA_ALTA`, `FECHA_BAJA`.

- **No es una segunda fuente de verdad.** La fecha vigente es la del maestro, siempre. Esta tabla no se consulta para saber qué fecha aplica; se consulta para saber **quién la puso**.
- **La marca de "Editada" se calcula, no se guarda.** `Comex::marcaVigente()` compara `FECHA_NUEVA` contra el maestro: si la app de Comex movió la fecha después, el rastro sigue siendo cierto pero ya no explica lo que hay en la celda, y el badge no aparece. Un bit persistido quedaría mintiendo desde el primer cambio hecho del otro lado — un cambio que este módulo no ve pasar.
- **No hay bajas físicas.** Editar dos veces la misma fecha marca `VIGENTE = 0` la anterior e inserta una nueva. Mismo criterio que `RO_T_CASHFLOW_ECHEQ_EXCLUIDO`: con un `UPDATE`, un dedazo corregido a los cinco minutos y una decisión que estuvo vigente tres semanas son indistinguibles después del hecho.
- **Un solo rastro vigente por contenedor y campo**, con índice único **filtrado** por `VIGENTE = 1`. Un `UNIQUE` común prohibiría también las filas históricas repetidas, que es lo que la tabla existe para guardar.
- **Sin FK contra el maestro**, a propósito: es una tabla de otra plataforma, y si un contenedor se depura allá, una FK nuestra haría fallar una depuración que no es nuestra. La base ya tiene dos ediciones huérfanas, así que el caso no es teórico.

### El criterio del conflicto en la migración

| Caso | Qué hace |
| --- | --- |
| El maestro está vacío | Se escribe la `EDIT`. No se pisa nada |
| El maestro dice lo mismo | No hay nada que escribir. Se deja el rastro igual, con la `_ORIG` guardada como valor anterior |
| El maestro dice **otra cosa** | **No se pisa.** El rastro entra con `VIGENTE = 0` y el script lo lista |
| El `ID_MG` no está en el maestro | No se migra. El script lo lista |

**Por qué gana el maestro.** No hay forma de saber cuál de los dos valores es más nuevo: la tabla del cashflow tiene `FECHA_UPDATE` y el maestro **no tiene fecha de modificación**, así que la antigüedad no se puede comparar. Ante el empate, el maestro es el dato que la app de Comex está mostrando hoy y que su circuito mantiene. Pisarlo con un valor del cashflow que puede ser de hace meses sería cambiar en silencio un dato ajeno — que es justamente lo que esta entrega vino a terminar.

En esta base el criterio no se usó (cero conflictos), pero en otra sí: por eso el script **los lista al final en vez de resolverlos solo**. La decisión de qué fecha vale no es del script.

### El centinela `1900-01-01` no es una edición

`FECHA_NAC_ORIG` y `FECHA_NAC_EDIT` nacieron `NOT NULL`, así que el guardado de la *otra* pestaña las rellenaba con esa fecha al insertar una fila. Tomarla como edición migraría al maestro una nacionalización en 1900, y en el tablero ese importe caería fuera del eje sin que nadie entienda por qué.

> Ese centinela **también era un bug de lectura**, y se fue solo con este cambio: `FECHA_NAC_EFECTIVA` salía de `FECHA_NAC_EDIT ?? FECHA_NAC`, y `??` sólo cubre `null` — un `1900-01-01` es un valor. Verificado contra la base: en `develop` había **tres contenedores** (`ID` 696, 704 y 708) mostrando **01/01/1900** como fecha de nacionalización, y en esta rama no queda ninguno. No movían un peso del tablero porque los tres tienen el importe estimado en `NULL`, pero la pantalla mostraba una fecha que nadie cargó, y el día que alguno tuviera gasto estimado su importe habría caído fuera del eje sin explicación. Leyendo del maestro no hay centinela que confundir.

### Si el DDL no se corrió

La pantalla **no explota**: las fechas salen del maestro, los vencidos se ven, la grilla se lee entera. Lo que se apaga es la **edición** —la celda deja de invitar al clic y `guardarFecha()` rechaza con el nombre del script—, y es a propósito: escribir sobre el maestro de otra plataforma sin dejar rastro de quién lo hizo es exactamente lo que esta etapa vino a terminar. Mismo patrón que `updateCotizacion()` sin `COTIZ_USD_EDIT`.

---

## 2. Se ven los vencidos

### El filtro que escondía más de la mitad del padrón

Las dos consultas cortaban con:

```sql
AND ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB) >= CAST(GETDATE() AS DATE)
```

y el tablero avisaba en cada carga que *"sólo se incluyen contenedores con fecha de embarque desde hoy"*. El aviso era correcto y no alcanzaba. Verificado contra la base el **19/09/2026**:

| | Contenedores |
| --- | --- |
| Sin detalle cargado (el universo) | **76** |
| Que el filtro dejaba pasar | 34 |
| **Que el filtro escondía** | **42** — 38 con embarque pasado y 4 sin fecha de embarque |

Y de los escondidos, **10 tenían la fecha de pago todavía por delante** y **18 la nacionalización por delante**. Eran egresos reales que el tablero informaba de menos.

**El filtro se fue.** Lo que decide dónde impacta un contenedor es **su fecha efectiva**, no cuándo embarcó.

### Cuánto se movió el tablero

| Fila | Antes | Ahora |
| --- | --- | --- |
| *Proveedores del Exterior* (dentro del horizonte) | $ 3.499.977.348 | **$ 4.632.182.810** |
| *Nacionalizaciones* (dentro del horizonte) | **$ 0** | **$ 561.423,77** |

La segunda es la que más dice. La fila de nacionalizaciones **daba cero** y el proveedor avisaba que *"ninguno tiene gastos de nacionalización estimados cargados"*. No era cierto en general: era cierto **de los 34 que el filtro dejaba pasar**. Los gastos estimados se cargan cuando el contenedor ya embarcó, así que el filtro escondía exactamente los contenedores que tienen el dato. El aviso describía el efecto del filtro y lo atribuía a la carga.

> **Ese `$ 561.423,77` eran dólares.** Las cifras de nacionalización de esta sección y de la siguiente son las que el tablero informaba entonces, y hoy se sabe que estaban en la moneda equivocada: se corrigió en `feature/comex-nac-usd` y está contado en la sección 6. Se dejan como estaban porque describen lo que movió **este** cambio; lo que cambia de moneda es otra cosa y se mide aparte.

### Qué hace el tablero con un importe cuya fecha efectiva ya venció

**Al cashflow entra lo que se paga de hoy en adelante. Un pago con la fecha ya vencida no suma.**

O el pago ya salió —y entonces no es proyección— o no salió y hay que corregirle la fecha. Las dos cosas son **gestión de Comercio Exterior sobre el dato**, y hasta que alguien la haga, ese importe no describe ningún movimiento futuro. Lo implementa `Comex::aporteAlEje()`.

**Por qué un campo aparte y no filtrar las filas.** La fila tiene que seguir viajando: la pestaña la muestra —escondida detrás del interruptor, pero ahí— y es la única forma de corregirle la fecha. El eje se arma sobre `IMPORTE_EJE`, que es `IMPORTE_ARS` salvo que vale **cero** cuando el pago venció; con importe cero, `Horizonte::agrupar()` saltea la fila entera. Así no entra en ninguna columna y tampoco cae en `fuera_horizonte`, que es otra cosa —lo que quedó después del último mes— y se arregla de otra manera.

`IMPORTE_ARS` **no se toca**: es lo que vale el contenedor y la grilla lo sigue mostrando en su columna. Lo que cambia es cuánto de eso entra al período.

> **Cero y no `null`.** `null` es *"no se pudo valuar"* y tiene su propio aviso, en dólares. Cero es *"vale, pero no entra"*. Son dos motivos distintos por los que una celda queda vacía, y la pantalla los informa por separado.

**Y no se reubica en hoy.** El importe queda en su fecha en vez de amontonarse en la primera columna. Eso se aparta de `Ingresos::ubicarCobroVencido()`, que es la regla única de las tres pestañas de cobranza proyectada y **sí** ubica lo vencido en el primer día del eje. Se aparta por dos razones que no valen allá:

1. **Acá la fecha se edita desde la pestaña.** El circuito correcto es que Comercio Exterior le cargue la fecha nueva, y para eso la fila ahora se ve. Reubicar en hoy pondría en la columna de hoy un egreso que nadie afirmó que sale hoy, y encima competiría con la corrección.
2. **Allá Tango dice si la factura sigue impaga**, así que reubicar es correcto: esa plata está pendiente con seguridad. Acá **no hay ninguna señal** de que el pago no se haya hecho — el único corte es que el contenedor todavía no tenga detalle cargado — y hay pagos vencidos de hasta **331 días**. Amontonar los 27 en la columna de hoy pondría en el peor día del tablero **$ 2.517 millones** que probablemente ya salieron.

### La misma regla en las dos pestañas

**Crono Nacionalización la aplica igual**: una nacionalización con la fecha vencida tampoco suma. Es la misma función — `aporteAlEje()` recibe el campo de importe de cada pestaña en vez de tenerlo escrito adentro, que habría obligado a copiarla. (Entonces eran dos campos distintos, `IMPORTE_ARS` en una e `IMPORTE_EST` en la otra; desde la sección 6 las dos pasan `IMPORTE_ARS`, que es el default, y el argumento queda por lo que hace posible.)

| Fila del tablero | Sin la regla | Con la regla |
| --- | --- | --- |
| *Proveedores del Exterior* | $ 4.888.951.400 | **$ 4.632.182.810** |
| *Nacionalizaciones* | $ 561.423,77 | **$ 506.185,65** |

La diferencia en cada una son los importes vencidos **del mes en curso**, que hasta entonces entraban igual: $ 256.768.590 en pagos (4 contenedores) y $ 55.238,12 en nacionalizaciones (1). Que entraran no era un error del eje, sino algo que no es obvio: la columna del mes en curso cubre los días de ese mes que quedaron fuera del tramo diario, **o sea días que ya pasaron**. Ese reparto lo decide `Horizonte::agrupar()`, es la regla de *"un importe va a un día O a un mes"* que hace sumables a las tres vistas, y **no se tocó**: lo que cambió es cuánto aporta una fila vencida, que ahora es cero.

> **`Comex::avisosVencidos()` conserva la capacidad de dar dos avisos** —los que entran en la columna del mes en curso y los que no—, pero desde que la regla vale en las dos pestañas ninguna se la pide: las dos llaman **sin pasarle el `Horizonte`**, porque no hay nada que repartir. La capacidad queda porque es lo que hace que el aviso no pueda mentir si alguien vuelve a dejar entrar lo vencido en algún lado.

> **Un aviso que hubo que corregir con esto.** `ComexProvider` avisaba *"hay N contenedores pero ninguno tiene gastos de nacionalización estimados cargados"* cuando la serie daba cero. Desde que una fila vencida aporta cero, la serie también puede dar cero **porque están todos vencidos**, que es otra cosa y tiene su propio aviso. Esa guarda pasó a medirse sobre el importe crudo (`totalImporte()`), no sobre la serie.

### Las vencidas no se ven por defecto

Las dos pestañas tienen un interruptor **Ver vencidas**, apagado al abrir.

Una fecha vencida es un dato a corregir, y hasta que alguien la corrija ese contenedor no participa del período que la pantalla proyecta: sus celdas del eje están vacías. En el trabajo normal —mirar qué se mueve de acá en adelante— son ruido, y son muchas: **27 de 76 filas** en Proveedores Exterior y **24 de 76** en Crono Nacionalización. Pero tienen que poder mirarse, porque son justamente las que hay que arreglar; por eso es un interruptor y no un filtro fijo.

**Cuánto esconde se dice al lado del interruptor, siempre** — *"27 vencidas escondidas"* o *"se ven las 27 vencidas"*. Una tabla que esconde filas sin decirlo se lee como que esos contenedores no existen. Es el mismo criterio que *Ver excluidos* de Echeqs y *Ver excluidas* de Proveedores Locales.

| | |
| --- | --- |
| **Dónde filtra** | En el navegador, escondiendo filas. Las 76 ya están cargadas: un round-trip por prender un interruptor sería trabajo puro |
| **Con el buscador** | Se combinan: los dos terminan en `filtrarTabla()`, así que no pueden quedar diciendo cosas distintas |
| **El pie** | Se rehace con lo visible, igual que con el buscador |
| **Las tarjetas** | **No** se tocan: miden el cronograma completo |
| **El export** | Baja lo que se ve, sin nada extra: `TablaExport` saca del clon las filas con `display: none` |
| **Qué mira** | El `data-vencida` del `<tr>`, no la clase CSS: la clase es presentación y podría cambiar sin que nadie piense en el filtro |

> **Esconderlas no cambia ningún número**, y eso es lo que hace al interruptor seguro: un pago vencido ya valía cero en el período, así que el pie y las tarjetas dicen lo mismo prendido o apagado. Verificado en las dos vistas. Es la diferencia con el buscador, que sí puede dejar el pie midiendo algo distinto de las tarjetas.

**Crono Nacionalización tiene el suyo**, idéntico: `verVencidasCronoNac`, apagado, con su contador. Son 24 de 76 filas.

> **Desde la sección 9, el interruptor mira las vencidas *pendientes*.** Una vencida que ya se pagó —tildada, o cancelada en Comercio Exterior— no hay que corregirla: la esconde *Ver pagados* y no lleva el badge rojo. Los importes siguen decidiéndose por `VENCIDA`, que no cambió.

> `ComexFechas.verVencidas()` devuelve `true` cuando la pantalla **no** declara el control. La guarda sigue importando aunque hoy las dos lo tengan: sin ella, una pestaña nueva que dibujara filas con `data-vencida` abriría escondiéndolas sin ningún control que las traiga de vuelta, y nada lo diría.

### Cómo se ven en la grilla

Tres marcas, tres cosas distintas, y las tres las decide el backend:

| Marca | Qué dice |
| --- | --- |
| **Manual** (amarillo, sólo fecha de pago) | Esta fecha está **fijada a mano** y el recálculo automático de Comercio Exterior no la pisa. Sale del BIT `FECHA_PAGO_CONF` del maestro |
| **Editada** (naranja, sólo fecha de nacionalización) | Esta fecha del maestro la puso alguien desde el cashflow. El tooltip dice quién, cuándo y qué decía antes |
| **Vencida** (rojo) | La fecha ya pasó **y el pago no se hizo** (desde la sección 9, una ya pagada no la lleva). El `title` explica la consecuencia: *"este importe no entra en ninguna columna del eje"*. En Proveedores Exterior además está escondida por defecto |
| **Sin fecha** (gris) | No hay dónde ubicar el importe en el tiempo. Es **otro problema** que vencida — uno se corrige, el otro se carga — y por eso es otra marca |

#### Por qué la fecha de pago dejó de decir "Editada"

Porque cambió lo que afirma. `FECHA_EST_PAGO` la **calcula** la pantalla de Comercio Exterior como *fecha de embarque + 5 días*, así que la pregunta útil sobre esa celda no es quién la tocó sino **si el recálculo se la va a llevar puesta**. Eso lo contesta `RO_T_IMPORTACIONES_ENCABEZADO.FECHA_PAGO_CONF`, un BIT del maestro que prenden las dos aplicaciones (script `comercioExterior/sql/10_fecha_pago_manual.sql`).

Comparar el rastro contra el maestro no alcanzaba: **una fecha fijada desde Comercio Exterior no deja rastro de este lado** — es otra aplicación — y quedaba sin marcar, indistinguible de una calculada.

El rastro **no se reemplaza, se suma**:

| | Qué contesta |
| --- | --- |
| `RO_T_CASHFLOW_COMEX_FECHA_EDIT` | **quién** la movió y **desde dónde** |
| `FECHA_PAGO_CONF` | **si está fijada** |

Por eso `guardarFecha()` escribe las dos cosas en la misma transacción, y la fila viaja con `EDITADA` (del BIT) **y** `RASTRO_VIGENTE` (de `marcaVigente()`) por separado: el front usa el segundo para elegir el tooltip. Si hay rastro vigente muestra el de siempre; si no, dice que la fijaron desde Comercio Exterior, que es lo único cierto.

**La de nacionalización no cambió.** No tiene BIT equivalente — está fuera de alcance — y su marca sigue queriendo decir *"esto lo movió el cashflow"*, que es lo único que se puede afirmar ahí.

> **Sin el script 10 corrido, la fecha de pago vuelve a `marcaVigente()`**, o sea a comportarse exactamente como antes. `tieneFechaPagoConf()` lo pregunta con `COL_LENGTH`, mismo patrón que `tieneCotizEdit()` y `tieneHistorial()`. Lo único que se pierde es poder marcar lo que se fijó del otro lado.
>
> **Reescribir la misma fecha no la deja fijada.** Es la misma regla de siempre — *si no cambia nada, no se escribe* — y tiene un borde: si la fecha que el usuario quiere resulta ser la que el cálculo automático ya puso, confirmarla tipeándola igual no la protege. Para fijarla hay que moverla, o hacerlo desde Comercio Exterior.

**Se marca la fila entera y no sólo la celda.** Con veintiocho columnas de días, el ojo está en la punta derecha de la tabla y la celda de la fecha quedó a un scroll de distancia. Es el mismo criterio con el que el tablero marca la columna entera y no sólo el *Saldo Final*.

En la celda de la fecha, **vencida le gana a editada**: la marca de editada dice de dónde salió el valor, la de vencida dice que ese valor deja el importe afuera del eje. El badge naranja sigue ahí al lado del rojo, así que no se pierde ninguna de las dos cosas.

> **El `title` explica la consecuencia, no el estado.** Lo que hay que saber mirando la celda no es *"esto venció"* sino *"por esto tu importe no está en ninguna columna"*.

---

## 3. Crono Nacionalización se lista por fecha de nacionalización

El listado **se ordena y se filtra por la fecha de nacionalización efectiva**, que es la que decide cuándo impacta el gasto.

Antes el orden era `COALESCE(FECHA_NAC_EDIT, FECHA_DESP_ADU, FECHA_ARR, FECHA_EMB, FECHA_EST_EMB)` y el corte era por fecha de embarque: **la tabla se leía por una fecha y se ordenaba por cualquiera de cinco**, así que dos contenedores con la misma nacionalización podían quedar en cualquier orden entre sí. Ahora manda `FECHA_DESP_ADU` y nada más; la de embarque queda como última desempatadora, que es información de la fila y no el criterio.

**Sin fecha de nacionalización la fila no se pierde.** Va al final del listado:

```sql
ORDER BY CASE WHEN A.FECHA_DESP_ADU IS NULL THEN 1 ELSE 0 END,
         A.FECHA_DESP_ADU,
         ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB)
```

Sin ese `CASE`, SQL Server las pone **primeras**, que es el peor lugar: lo primero que se lee sería lo que no se puede ubicar. La fila se lista igual —`EjeVista::armar()` devuelve todas, tengan importe en el eje o no—, se marca *Sin fecha*, y su importe lo informa el aviso de descartes del eje.

Hoy **todos** los contenedores tienen fecha de nacionalización —la calcula la app de Comercio Exterior— y está verificado contra la base. El caso se resuelve igual porque una fila que desaparece por un dato que falta es lo que este módulo evita en todos lados.

Lo mismo se aplicó a Proveedores Exterior con `FECHA_EST_PAGO`, donde el caso **sí** existe: hay 5 contenedores sin fecha de pago.

---

## 4. El tilde de pagado

El cashflow proyecta **lo que falta pagar**. Un contenedor cuyo pago ya se hizo seguía apareciendo —el maestro de Comercio Exterior no dice si se pagó— y su importe seguía sumando como un egreso por venir. Ahora se tilda, y sale.

Y es lo que **resuelve los vencidos**: un pago con la fecha pasada ya no suma, pero seguía en la grilla esperando que alguien hiciera algo con él. Este tilde es ese algo, sin inventar una fecha que nadie conoce.

### El dato es del cashflow

A diferencia de las fechas —que se escriben sobre el maestro porque son el mismo dato para las dos aplicaciones— esto es una **afirmación del cashflow sobre su propia proyección**: *"este egreso ya no lo esperamos"*. Comercio Exterior no tiene hoy ese concepto, y no se le agrega una columna a su maestro para un circuito que es nuestro. Si más adelante lo quiere ver, lo resuelve con una consulta: `ID_MG` es la clave del contenedor en su maestro.

> **Por qué una tabla propia y no columnas en `RO_T_CASHFLOW_COMEX_CRONO_NAC`**, que es donde el pedido la ubicaba. Esa tabla tiene **una fila por contenedor** y no puede llevar historial: pisar la marca con un `UPDATE` haría indistinguibles un tilde puesto por error y corregido a los cinco minutos de una decisión que estuvo vigente tres semanas. Y no alcanzaría con un flag: **hay dos pagos por contenedor** y son plata distinta, así que la clave es `(ID_MG, CONCEPTO)`. Es el mismo criterio de `RO_T_CASHFLOW_ECHEQ_EXCLUIDO`.

### El importe no desaparece: cambia de serie

```
PAGOS + PAGOS_PAGADOS + PAGOS_COMEX = PAGOS_TODO
NACIONALIZACION + NACIONALIZACION_PAGADAS      = NACIONALIZACION_TODO
```

Mismo criterio que la exclusión de cheques de cartera: un importe que sale del tablero sin dejar rastro es un agujero que nadie puede auditar. `PAGOS` y `NACIONALIZACION` **cambian de significado y no de código** —pasan a ser *"lo que falta pagar"*—, así que **no hay que repuntar ninguna fila** ni tocar Parámetros. El día que se corre el script la tabla nace vacía y el tablero no se mueve un peso.

> **`PAGOS_COMEX` es de la sección 8**, no de ésta. Aparece acá porque el invariante es uno solo: desde `feature/comex-saldo-pendiente` hay **dos formas distintas** de que un egreso salga de la proyección de Proveedores Exterior —el tilde de esta sección y un pago cargado en la otra aplicación— y el tablero tiene que poder contestar cuál de las dos fue. *Crono Nacionalización* sigue con dos partes: allá no hay pagos parciales contra un saldo.

El proveedor informa en cada carga cuánto se marcó, con el conteo. Sin ese aviso, un egreso que el tablero debería estar proyectando desaparece y nada en pantalla lo explica.

**Cómo cierra el invariante**, que es la parte delicada:

| Serie | Campo | Sobre |
| --- | --- | --- |
| `PAGOS` | `IMPORTE_EJE` — vale cero si está pagado **o** vencido | todas las filas |
| `PAGOS_PAGADOS` | `IMPORTE_PROYECTABLE` — vale cero sólo si está vencido | las marcadas |
| `PAGOS_COMEX` | `IMPORTE_PAGADO_PROYECTABLE` | todas |
| `PAGOS_TODO` | `IMPORTE_FOB_PROYECTABLE` — el FOB **entero** | todas |

Los cuatro campos salen de la misma fila y **se anulan juntos** cuando está vencida, así que las cuatro series dan cero ahí y el invariante sigue cerrando. Para una fila no marcada, `PAGOS` lleva el pendiente y `PAGOS_COMEX` lo ya pagado, y los dos suman el FOB; para una marcada, `IMPORTE_EJE` es cero y ese pendiente aporta a `PAGOS_PAGADOS`. Por eso las reglas viven en funciones separadas (`importeProyectable()` y `aporteAlEje()`) en vez de en una sola.

> **El tilde se aplica sobre el pendiente, no sobre el FOB.** Tildar un contenedor que ya tiene la mitad pagada en Comex saca de la proyección **la mitad que faltaba**, que es lo único que estaba proyectado. La otra mitad ya había salido por `PAGOS_COMEX`.

**Verificado contra la base**, marcando una fila real en cada pestaña y deshaciéndolo:

| | Antes | Después | Diferencia | Importe de la fila |
| --- | --- | --- | --- | --- |
| `PAGOS` (contenedor 690) | $ 4.632.182.810 | $ 4.546.462.890 | **$ 85.719.920** | **$ 85.719.920** |
| `NACIONALIZACION` (contenedor 635) | U$S 506.185,65 | U$S 448.091,37 | **U$S 58.094,28** | **U$S 58.094,28** |

En los dos casos el universo (`..._TODO`) **no se movió**, el invariante cerró antes y después, y destildar devolvió el total al original.

> **Marcar algo que ya estaba vencido no mueve ningún número**: ya valía cero. Lo que cambia es que la fila sale de la pantalla y deja de pedir atención, que es justamente para lo que se marca.

### Cómo se usa

- **El tilde actúa, no selecciona.** A diferencia del de Echeqs —donde el check elige filas y un botón confirma el lote con su motivo— acá cada clic guarda. La diferencia está en qué se afirma: allá es una decisión discutible que saca plata del disponible y necesita un motivo por escrito; acá es un hecho, *"este pago se hizo"*. Y se deshace con el mismo clic, que es lo que lo hace seguro.
- **La observación es opcional**, por lo mismo. Obligar a escribir algo terminaría en doscientas filas que dicen "pagado".
- **Interruptor *Ver pagados*, apagado por defecto**, con el conteo al lado. Igual que el de vencidas y que el *Ver excluidos* de Echeqs.
- **No hay bajas físicas.** Destildar marca `VIGENTE = 0` y sella `FECHA_BAJA`; volver a marcar inserta una fila nueva. Quién marcó y cuándo va en el `title` de la casilla.
- **Son dos tildes por contenedor y no se cruzan**: marcar el pago al proveedor del exterior no dice nada del gasto de nacionalización. Por eso la clave lleva `CONCEPTO`.
- **La fila pagada se atenúa, no se tacha.** Sigue siendo un dato correcto —el contenedor existe y ese importe se pagó—; el tachado se lee como *"esto está mal"*. La celda del tilde **no** se atenúa: es el control con el que se destilda.

## 5. El buscador

Proveedores Exterior no tenía. Ahora tiene **el mismo** que Crono Nacionalización — no uno parecido: **el mismo código**.

Los dos viven en `cashflow/Js/Comex-fechas.js`, junto con la celda de fecha editable. Estaban copiados y **ya habían divergido**: el editor de pago avisaba cuando el cambio de mes descartaba la cotización y el de nacionalización no tenía ese aviso ni la guarda que evita que el `blur` dispare un segundo guardado después del Enter. Con el maestro de por medio esa divergencia deja de ser cosmética: son dos gestos distintos para escribir sobre la tabla de otra aplicación.

| | |
| --- | --- |
| **Qué mira** | Sólo **Proveedor**, **Contenedor** y **Orden de Compra** |
| **Cómo llega a la fila** | En un `data-buscar` sobre el `<tr>`, armado al dibujarla |
| **Cuándo se reaplica** | Al redibujar: cambiar de vista o refrescar no puede hacer reaparecer lo que el usuario filtró, con el campo todavía escrito |
| **Los totales** | Se rehacen con lo visible |
| **Las tarjetas de arriba** | **No** se tocan: miden el cronograma completo |
| **El export** | Baja lo que se ve |

**Por qué esos tres campos y no el `textContent` de la fila entera** —que es lo que hace Cobranzas May—: la tabla tiene una columna por día del eje, así que buscar sobre todo daría falsos positivos contra los importes. Tipear `2026` traería todo, y tipear un número de tres cifras, cualquier fila que tenga ese número adentro de un importe.

**Insensible a mayúsculas, no a acentos.** Es lo que hacen Echeqs y el resto del módulo; agregar el plegado de acentos acá sólo haría que estos dos buscadores se comporten distinto de los otros cuatro.

### Los totales y el export

Proveedores Exterior tenía **una columna de total que el buscador no filtraba**: la suma en pesos del pie (`sumaImporteArs()`) recorría todas las filas del payload. Si sumara todo mientras la tabla muestra tres filas, esa celda y la del eje que tiene al lado dirían números de dos universos distintos sin que nada lo indique. Ahora las dos respetan el filtro.

**El export pasó a `data-exportar`.** Tenía un `#btnExport` con listener propio y una función envoltorio de una línea que ya llamaba a `exportarTabla()`: tres piezas que el atributo hace solo. Con el atributo lo engancha `Js/tabla-export.js`, que saca del clon las filas con `display: none`, así que *Exportar* baja lo que el buscador está dejando ver. Es el mismo cambio que Crono Nacionalización ya había hecho, y acá se volvió **necesario** al haber buscador: sin él, *Exportar* habría bajado las 76 filas mientras la pantalla mostraba tres.

> Otras pestañas del módulo siguen enganchando el botón a mano. No es deuda de esta rama y cambiarlo acá habría metido en esta etapa archivos que no tienen nada que ver; está anotado en `README-cashflow.md`.

---

## 6. Los gastos de nacionalización estaban en dólares

Rama: `feature/comex-nac-usd`

### Lo que decía el código, y por qué era falso

`ComexProvider::nacionalizaciones()` afirmaba en su docblock:

> *Gastos de nacionalizacion. Ya estan en pesos, no hay conversion.*

No era una mejora pendiente: **era un error de moneda**. `IMPORTE_EST` es `SUM(IMPORTE)` sobre `RO_T_IMPORTACIONES_ESTIMACION_DETALLE` con `ID_CE BETWEEN 3 AND 10`, y esos ocho conceptos los calcula la pantalla de Comercio Exterior como porcentajes del CIF:

```
administracion/comercioExterior/js/editar-estimacion.js
    fob  = VALOR_FOB_DOLAR          ← la cadena entera arranca acá
    cif  = fob + flete + seguro
    derechos        = cif * derechosParam          (ID_CE 3)
    tasaEstadistica = cif * tasaParam              (ID_CE 4)
    baseImponible   = cif + derechos + tasaEstadistica
    ivaGeneral      = baseImponible * ivaParam     (ID_CE 5)  … y así hasta ID_CE 10
```

**El tablero venía ubicando dólares en columnas de pesos**, y sumándolos contra el resto del cashflow.

### Cómo se verificó contra la base

Antes de tocar nada, y de dos maneras independientes. Al **21/09/2026**, sólo lectura:

**1. El orden de magnitud.** Si `IMPORTE_EST` estuviera en pesos, su cociente contra el FOB en dólares tendría que dar del orden de mil —la cotización—. Da una fracción:

| | Contenedores | Cociente `IMPORTE_EST / VALOR_FOB_DOLAR` |
| --- | --- | --- |
| Con estimación cargada | 12 | **mínimo 0,71 · promedio 0,87 · máximo 1,04** |

| ID | Contenedor | OC | `VALOR_FOB_DOLAR` | `IMPORTE_EST` | Cociente |
| --- | --- | --- | ---: | ---: | ---: |
| 733 | INV04-27 | 0000100015881 | 77.408,00 | 71.200,76 | 0,92 |
| 691 | VER01-26 | 0000100014945 | 24.750,00 | 25.710,50 | 1,04 |
| 682 | VER01-26 | 0000100014861 | 185.937,00 | 152.438,87 | 0,82 |
| 647 | VER11-26 | 0000100014378 | 60.718,00 | 43.304,36 | 0,71 |

**2. La cuenta, concepto por concepto.** En el contenedor 733 —FOB 77.408 USD, flete 5.000, seguro 41,20— el detalle cierra exacto sobre un CIF construido en dólares:

| | | |
| --- | ---: | --- |
| CIF = FOB + flete + seguro | 82.449,20 | |
| Derechos (`ID_CE` 3) | 16.489,84 | **20,0000 %** del CIF |
| Tasa estadística (4) | 2.473,48 | **3,0000 %** del CIF |
| Base imponible = CIF + derechos + tasa | 101.412,52 | |
| IVA general (5) | 21.296,63 | **21,0000 %** de la base |
| IVA adicional (6) | 20.282,50 | **20,0000 %** de la base |
| IIGG (7) | 6.084,75 | **6,0000 %** de la base |
| IIBB (8) | 4.563,56 | **4,5000 %** de la base |
| SIM (9) + Antidumping (10) | 10,00 | importes fijos |
| **Suma 3 a 10 = `IMPORTE_EST`** | **71.200,76** | |

Porcentajes exactos sobre una base armada en dólares. No hay lugar donde se haya convertido nada.

### Qué cambió

Crono Nacionalización funciona ahora **igual que Proveedores Exterior**:

| | |
| --- | --- |
| **Se valúa fila por fila** | Con la curva de dólar futuro ROFEX del mes de su **fecha de nacionalización** —no la de pago— en `Comex::valuar()`, la misma función de la otra pestaña |
| **Qué se ubica en el eje** | `IMPORTE_ARS`, o sea el importe **en pesos**. `IMPORTE_PROYECTABLE` e `IMPORTE_EJE` salen de ahí |
| **Qué muestra la grilla** | Tres columnas donde había una: *Importe Est. (USD)*, *Dólar aplicado* e *Importe ($)* |
| **La serie del tablero** | `moneda_origen` pasa de `ARS` a `USD` en las tres, y `tipo_cambio` informa el escalar sólo si todas las filas se valuaron igual. Lo mismo en `CashflowRegistry`, que también decía `ARS` |

**La fecha que manda es la de nacionalización, y eso no es un detalle.** Es el mismo contenedor que Proveedores Exterior, pero los dos egresos se mueven en momentos distintos, así que les toca un punto distinto de la curva. Si la nacionalización mirara la fecha de pago, el gasto quedaría valuado con el dólar de un mes en el que no se mueve.

`Comex::valuar()` recibe ahora el campo del importe y el de la fecha, con los de Proveedores Exterior por defecto, así que su llamada no cambió. `Comex::avisosValuacion()` recibió el mismo tratamiento por un motivo concreto: con los campos escritos adentro, el aviso de esta pestaña habría dicho *"U$S 0,00"* —sumando `VALOR_FOB_DOLAR`, que acá no existe— y habría nombrado la fecha de pago, que no es la que falta.

### Cuánto se movió el tablero

Verificado contra la base el **21/09/2026**, sobre las mismas 76 filas:

| Fila *Nacionalizaciones*, dentro del horizonte | |
| --- | ---: |
| Antes (dólares puestos en columnas de pesos) | 577.386,41 |
| **Ahora** (pesos) | **$ 906.015.190,46** |

El universo en dólares es `U$S 699.828,59` y su valuación completa `$ 1.092.094.189,02`; la diferencia contra los 906 millones son las **24 vencidas**, que no suman en ninguna columna. El invariante `NACIONALIZACIÓN + NACIONALIZACIÓN_PAGADAS = NACIONALIZACIÓN_TODO` **cierra columna por columna**, ahora en pesos.

Dos avisos cambiaron de número por el mismo motivo:

- el de vencidos ahora informa **$ 186.078.998,56** en vez de un importe en dólares con el signo de pesos adelante;
- aparece el de **8 contenedores valuados con el mes más cercano**, todos hacia atrás: son nacionalizaciones vencidas anteriores al inicio de la curva. Es el mismo caso que `DolarFuturo::mesMasCercano()` ya cubría en la otra pestaña.

### La cotización acá no se edita, y es una decisión

En Proveedores Exterior existe el override por contenedor —`COTIZ_USD_EDIT`, en `RO_T_CASHFLOW_COMEX_CRONO_NAC`—. **No se extendió a esta pestaña**, y la celda se muestra de sólo lectura con el mismo tooltip que explica de qué mes salió la cotización y si se aproximó.

Esa tabla tiene **una fila por contenedor**, y las dos pestañas valúan **el mismo contenedor en dos fechas distintas**, que caen en meses distintos y por lo tanto en puntos distintos de la curva. Compartir el override sería aplicarle a la nacionalización una corrección que alguien cargó pensando en el pago. Y `descartaCotizacion()` está atada al cambio de mes **del pago**: no sabe nada de la otra fecha. Está anotado en *Pendientes conocidos* lo que haría falta para tenerlo.

### Lo que no se copió

`celdaCotizacion()` e `importeArs` vivían en `Comex-Proveedores_exterior.js` porque ésa era la única pestaña que valuaba en dólares. Ahora valúan las dos, así que **la parte de sólo lectura de las dos celdas se mudó a `Js/Comex-fechas.js`** —el archivo compartido— y Proveedores Exterior le agrega encima su comportamiento editable pasándole `editable` y `alEditar`. Lo mismo con el texto del pie que dice de dónde sale el dólar.

Es la misma regla que ya cubren las pruebas de cableado para el buscador y la celda de fecha, y ahora también cubren esto.

### Las columnas fijas se corrieron

`Js/columnas-fijas.js` guarda la elección **por índice** en `localStorage`, y sólo valida que el índice siga existiendo: no tiene forma de saber que la columna 6 dejó de ser *ETD* y ahora es *Dólar aplicado*. Con dos columnas nuevas, quien tuviera fijada una columna a la derecha del importe la habría visto correrse dos lugares sin nada que lo explicara.

Por eso la clave de esta pestaña pasa a ser `crono_nacionalizacion_v2`: la preferencia vieja se descarta una vez y vuelve al default (*Proveedor* y *Contenedor*), que es lo único que no puede mentir. El default no cambió: son los índices 1 y 2, que siguen siendo esas dos columnas.

---

## 7. Los avisos de acción dejan de ser `alert()`

Rama: `feature/comex-nac-usd`

`Js/notificaciones.js` ya existe, está documentado y se carga desde `cashflow/index.php`, así que no hubo nada que enchufar: sólo reemplazar los nueve `alert()` que quedaban en las dos pestañas. No había ningún `confirm()`.

| Qué es | Adónde va |
| --- | --- |
| Falló guardar el tilde de pagado, o la fecha, o la cotización | `Notificacion.error()` |
| Error de conexión en cualquiera de los tres | `Notificacion.error()` |
| **Se guardó la fecha** | `Notificacion.exito()`, o `Notificacion.advertencia()` si se descartó la cotización |
| No se pudo cargar la pestaña | Panel adentro de la tabla **más** `Notificacion.error()` |

**Los fallos van a `error()` porque no se auto-cierra.** El mensaje del servidor es lo único que explica por qué el dato no quedó guardado, y que se borre a los cuatro segundos es perderlo. Los tres traen además qué pasó con lo que se estaba editando: la celda volvió a lo que decía, el tilde volvió a donde estaba.

### El aviso de la fecha es el que más cambió

Sale **siempre**, aunque haya salido todo bien, porque mover esa fecha cambia un dato de otra aplicación y eso no se deduce mirando la grilla. Eso no cambió. Lo que cambió es que **los dos casos ya no salen idénticos**:

- se guardó y no pasó nada más → **éxito**, que se cierra solo;
- se guardó **y el cambio de mes descartó la cotización cargada a mano** → **advertencia**, con su propio título. Ahí cambió además un importe que el usuario no tocó.

Con `alert()` los dos salían iguales, así que el que había que leer se cerraba con el mismo reflejo que el de todos los días. Es exactamente el problema 3 que `notificaciones.js` enumera en su encabezado.

### La falla de carga no es una notificación

Los dos `mostrarError()` no son como los otros siete. No falló una acción: falló **la carga entera**, y lo que queda en pantalla es una **tabla vacía**. Un mensaje efímero no sirve ahí: alguien que llega treinta segundos después, o que vuelve de otra pestaña, ve un cronograma sin contenedores y no tiene dónde enterarse de por qué.

El README de este módulo distingue **aviso sobre los datos** —se pinta, queda a la vista, no se cierra— de **notificación sobre una acción** —efímera, se descarta—. Esto está en el medio, así que hace las dos cosas:

| | |
| --- | --- |
| **Dónde se pinta** | Adentro de `tableWrapper`, que es donde está el vacío que hay que explicar, y con el consejo de probar *Actualizar* |
| **Qué pasa con la tabla** | **No se pisa.** El panel se inserta como primer hijo del contenedor; con un `innerHTML` sobre el wrapper, *Actualizar* no tendría dónde dibujar cuando el servidor vuelva |
| **Cuándo se va** | Al empezar la carga siguiente. Desde ese momento describe algo que ya no se sabe si sigue pasando |
| **La notificación** | Se manda igual, como complemento, para el que estaba mirando en el momento |

Las dos cosas viven en `Js/Comex-fechas.js` —`errorDeCarga()` y `limpiarErrorDeCarga()`— por la misma razón que el resto de lo compartido: es la misma falla con la misma consecuencia en las dos pestañas.

> En Crono Nacionalización `mostrarError()` lo llaman además los tres casos de *"no hay nada para mostrar"* de `generarTabla()`, que terminan igual: una tabla vacía que alguien tiene que poder interpretar. **Por eso el panel dice qué pasa —*la tabla quedó vacía*— y deja la causa en el mensaje del backend**: afirmar "no se pudieron cargar los datos" sobre un servidor que contestó bien y no tenía filas sería contar otra cosa.

---

## 8. Proveedores Exterior proyecta lo que FALTA pagar

Rama: `feature/comex-saldo-pendiente`

La app de Comercio Exterior permite **pagos parciales** al proveedor del exterior —un anticipo, un saldo contra embarque— y los guarda en `RO_T_IMPORTACIONES_ENCABEZADO_PAGOS`. El cashflow no los miraba: proyectaba el `VALOR_FOB_DOLAR` entero. Un contenedor con el anticipo ya girado entraba al tablero **por el total**.

Ahora entra por el saldo.

### La regla es la de Comex, replicada y no incluida

```
pendiente U$S = VALOR_FOB_DOLAR − SUMA(RO_T_IMPORTACIONES_ENCABEZADO_PAGOS.MONTO)
```

Es literalmente la cuenta de `Pagos::obtenerResumen()` del repo `administracion`, con los **mismos cuatro estados** (`SIN_FOB`, `PENDIENTE`, `CANCELADO`, `SOBREPAGO`) y **la misma tolerancia de un centavo**. `MONTO` está en dólares desde `comercioExterior/sql/08_pagos_en_dolares.sql`.

**Replicada, no incluida**: son dos aplicaciones y dos despliegues, y el cashflow no puede requerir un archivo que vive en otro repo. Lo que sí tiene que hacer es dar **exactamente el mismo número**, y eso es lo que la deja en `Comex::saldoPendiente()`, que es pura, se prueba sin base y se puede comparar línea por línea contra la otra.

> **Si la regla del saldo cambia allá, hay que cambiarla acá.** No hay forma de que el código lo detecte solo. El encabezado de `Pagos.php` del otro lado lo dice —`docs/pagos-lectura-cashflow` en ese repo lo corrigió, porque afirmaba que nadie más leía esa tabla— y el encabezado de `Class/Comex.php` es la otra mitad del pacto.

### El FOB y los pagos salen de la OC principal

`COALESCE(ID_PADRE, ID)`, igual que `encabezado.php::resolverIdPrincipal()`: **un contenedor con varias órdenes de compra tiene un solo pago al proveedor**, no uno por orden.

**Verificado contra la base el 22/09/2026, y hoy no cambia ninguna fila**: de los 76 contenedores del listado, los 76 son principales. No hay **ni una** fila con `ID_PADRE` cargado en `central`, ni pagos imputados a una hija. Así que la pregunta del pedido —*si una hija tiene FOB propio, o si principal e hija se suman dos veces*— **hoy no se puede responder con datos, porque no hay hijas**.

Lo que sí introduce este cambio es el **riesgo**: hasta ahora cada fila proyectaba su propio FOB, así que dos filas del mismo grupo eran dos importes distintos y no había nada que deduplicar. Ahora las dos leerían el FOB y los pagos **de la principal**, o sea que valdrían lo mismo, y sumar las dos contaría el egreso dos veces. Por eso el corte se implementa igual, aunque esté dormido:

| | |
| --- | --- |
| **Quién lleva el importe** | Una sola fila por grupo: la principal si está en el listado, y si no la de `ID` más chico |
| **Por qué el respaldo** | El listado deja afuera los contenedores con detalle cargado, así que puede pasar que la principal no esté. Dejar el grupo sin titular haría **desaparecer** ese egreso, que es peor que elegir por un criterio estable |
| **Qué hacen las demás** | Van en cero en **las cuatro series** —si `PAGOS_TODO` las contara, el invariante quedaría sin cerrar por el FOB entero— y quedan marcadas *Repetido* en la grilla, con su motivo en el `title` |
| **Cómo se calcula** | `ROW_NUMBER()` particionado por la OC principal. Las funciones de ventana corren **después** del `WHERE`, así que dos filas sólo se pisan si las dos están efectivamente en la grilla |

El aviso correspondiente **no manda a corregir nada**, a diferencia del de vencidos y el de sobrepago: no hay nada que corregir. Es como Comercio Exterior modela un contenedor con varias órdenes, y el cashflow se limita a no contarlo dos veces.

### Los tres casos de borde

| Caso | Qué hace el cashflow |
| --- | --- |
| **Sin pagos** | `pendiente = FOB`. Exactamente lo que se mostraba antes de esta rama |
| **Saldo cero** (cancelado en Comex) | Sale del flujo **solo**, sin que nadie lo tilde: su pendiente vale cero, así que no entra en ninguna columna. El importe se sirve por `PAGOS_COMEX` y el invariante sigue cerrando. **El tilde manual sigue existiendo** para lo que no se cargó allá |
| **Sobrepago** | El pendiente se toma como **cero, nunca negativo** —un egreso negativo sería un ingreso que nadie afirmó, y encima compensado en silencio contra el resto de la columna de ese día—. El exceso se informa aparte, **en dólares**, que es la moneda en la que se va a ir a buscar del otro lado |

> **Pagos con fecha futura**: se cuentan todos, porque en Comex un pago cargado es un hecho. **Verificado contra la base el 22/09/2026: no hay ninguno** con `FECHA_PAGO` posterior a hoy —los 75 pagos van del 11/09/2025 al 21/09/2026—.

### La valuación no cambió de criterio

Sigue siendo el **dólar futuro ROFEX del mes de la fecha efectiva de pago** (`Comex::valuar()`), aplicado al **pendiente** en vez de al FOB. El override manual `COTIZ_USD_EDIT` tampoco cambió: es una corrección sobre **qué dólar** se aplica, no sobre qué importe, y `descartaCotizacion()` sigue atada al cambio de mes del pago. Un contenedor cancelado con override cargado vale cero, porque cero por cualquier cotización es cero — y eso es lo correcto: lo que dejó de haber es el importe, no el dólar.

**Los avisos de valuación pasan a hablar del pendiente.** Con `VALOR_FOB_DOLAR` dirían de más justamente en los contenedores que ya tienen pagos hechos, que son los únicos donde los dos números difieren.

### Qué se ve en la pestaña

- **Tres columnas en dólares, en ese orden: FOB total, pagado y pendiente.** La resta escrita de izquierda a derecha, que es lo que hace que el número de la punta no haya que creerlo. Son los mismos números que muestra la pantalla de pagos de Comercio Exterior.
- **El importe en pesos, los totales de la pestaña, el export y la fila del tablero salen del pendiente.**
- **El pie totaliza las tres columnas en dólares**, sobre las filas que se están viendo. Es donde se ve el cuadre del módulo entero sin sumar 76 filas a mano.
- **La celda de pagado se abre** y muestra los pagos uno por uno —fecha, forma, medio, monto y cuándo se cargó—, con un contador al lado que dice cuántos son. Un mismo total puede salir de un anticipo o de seis cuotas.
- **El estado va pegado al pendiente** cuando no es el caso normal: *Parcial*, *Cancelado*, *Sobrepago*, *Sin FOB* o *Repetido*. Un contenedor sin pagos no lleva nada, porque no hay nada que explicar.
- **Las canceladas se marcan en verde** y **no tienen interruptor para esconderlas**, a diferencia de vencidas y pagadas. Es deliberado: acá no hay nada que corregir ni que destildar, así que un interruptor sólo agregaría un control más que entender. Y sus tres columnas en dólares son el comprobante de que el contenedor se pagó entero.

> **Sólo lectura, y no es una etapa pendiente.** Los pagos se cargan en Comercio Exterior, que es el dueño del circuito; un alta de este lado serían dos formularios escribiendo la misma tabla con dos validaciones distintas. Es la decisión **opuesta** a la de la fecha estimada de pago —que sí se edita desde acá, ver la sección 1— y la diferencia está en quién es dueño del dato: la fecha la usan las dos aplicaciones, el pago al proveedor lo registra una sola.

### Si no se puede leer la tabla

Es de la otra plataforma, así que **se pregunta antes de nombrarla**: nombrar una tabla ausente rompe la pantalla entera con *"Invalid object name"*, que es un error que no se ve hasta que alguien la abre. Sin la tabla, el pendiente vuelve a valer el FOB completo —o sea lo que esta pestaña mostraba antes de esta rama— **y se avisa**.

> **Es la única degradación del módulo que cambia números en vez de apagar un botón.** Las otras tres —el rastro de fechas, el tilde de pagado, el override de cotización— apagan una función y todo lo demás sigue igual. Ésta hace que el tablero proyecte **de más**, que es plata que puede haber salido ya, y por eso su aviso va primero y el texto dice que se está proyectando de más, no sólo que falta algo.

### Cuánto se movió el tablero

Verificado contra la base el **22/09/2026**, sobre los 76 contenedores del listado:

| | U$S |
| --- | --- |
| FOB total | 4.707.371,91 |
| Ya pagado en Comercio Exterior | **34.750,00** |
| Pendiente — lo que el cashflow proyecta ahora | 4.672.621,91 |

Son **2 contenedores** los que tienen pagos: el `733` (OC `0000100015881`) con un anticipo parcial, y el `691` (OC `0000100014945`) cancelado con dos pagos. Ningún sobrepago, ninguna OC repetida.

**El contenedor 733, contra `Pagos::obtenerResumen(733)` de Comercio Exterior:**

| | U$S |
| --- | --- |
| `VALOR_FOB_DOLAR` | 77.408,00 |
| Pagos cargados (1, del 21/09/2026, transferencia anticipada) | 10.000,00 |
| **Pendiente** | **67.408,00** |

Los tres coinciden con lo que devuelve esa función. La prueba `tests/test_comex_saldo_pendiente.php` los fija: si falla, una de las dos aplicaciones se movió.

**El total de la pestaña, antes y después:**

| | $ |
| --- | --- |
| Pie de la grilla, antes | 7.210.653.092,56 |
| Pie de la grilla, después | 7.155.531.592,56 |
| **Diferencia** | **55.121.500,00** |
| Suma de los pagos de los contenedores listados, cada uno a la cotización de su fila | **55.121.500,00** |

Cuadra exacto: `24.750 × 1.534` (el 691, que paga en septiembre) más `10.000 × 1.715,50` (el 733, que paga en marzo de 2027).

**En el horizonte la diferencia es menor**, y eso también cierra:

| | $ |
| --- | --- |
| Total del horizonte, antes | 5.842.518.578,32 |
| Total del horizonte, después | 5.825.363.578,32 |
| **Diferencia** | **17.155.000,00** |

El 691 **ya valía cero** —su fecha de pago está vencida—, así que cancelarlo no movió ninguna columna: lo único que cambió es que ahora la pestaña dice por qué. La diferencia es exactamente el pago del 733.

`PAGOS_TODO` quedó en **$ 7.210.653.092,56**, que es el total que el pie de la grilla tenía antes de la rama: el antes y el después se pueden leer en el mismo tablero. **El invariante cerró en las 40 columnas del eje**, sin un centavo de descuadre.

---

## 9. Una vencida que ya se pagó no es una vencida

> Esto **cambió** en `feature/cashflow-vto-exclusiones-pestanas`, y vale en las dos pestañas.

Una fecha vencida es un dato a corregir: o el pago salió, o hay que cargarle la fecha nueva. El contador *"N vencidas escondidas"*, el interruptor **Ver vencidas**, el badge rojo y el aviso *"tienen la fecha ya vencida… cargales la fecha nueva"* existen para eso. Pero miraban `VENCIDA` a secas, que sólo dice que la fecha pasó, y **contaban igual los pagos que ya se habían hecho**.

No era un caso de borde. Medido contra la base el **24/09/2026**:

| | Vencidas | de ésas, ya pagadas | Vencidas a corregir |
| --- | --- | --- | --- |
| Proveedores Exterior | 10 ($ 679.390.192,00) | **10** — 8 tildadas, 2 tildadas y además canceladas en Comex | **0** |
| Crono Nacionalización | 21 ($ 505.714.709,79) | **21** — tildadas | **0** |

El aviso le pedía a alguien que corrigiera treinta y una fechas que nadie tenía que tocar.

### Pagada es cualquiera de dos cosas

- el **tilde** de pagado de la sección 4, que puso alguien desde la pestaña;
- el saldo **`CANCELADO`** de la sección 8, por los pagos cargados en Comercio Exterior, **aunque nadie lo haya tildado**. Su pendiente es cero y no hay nada que corregir.

Un pago **parcial** en Comex no alcanza: todavía falta plata, y la fecha sigue pidiendo atención.

### Un flag aparte, y `VENCIDA` no se toca

Ésta es la parte delicada. `importeProyectable()` anula por `VENCIDA`, y de ese campo salen `PAGOS_PAGADOS`, `PAGOS_COMEX` y `PAGOS_TODO` (sección 4). Si una fila pagada dejara de ser `VENCIDA`, **su importe pasaría a sumar en `PAGOS_PAGADOS` y el tablero se movería**: en Proveedores Exterior serían los $ 679,4 millones de la tabla de arriba.

Por eso la fila viaja con **dos** flags:

| Flag | Qué dice | Qué decide |
| --- | --- | --- |
| `VENCIDA` | La fecha ya pasó | **Los importes**: `importeProyectable()`, `aporteAlEje()` y con ellos las series. No cambió |
| `VENCIDA_PENDIENTE` | Vencida **y** no pagada —ni tildada ni cancelada— | **Lo que se muestra**: el contador, el interruptor Ver vencidas (vía `data-vencida`), el badge y la marca de la fila, y el aviso de vencidos |

La regla está escrita una vez, en `Comex::vencidaPendiente()`, que es pura. Se calcula **al final** de cada consulta, porque necesita el tilde y el saldo ya resueltos, y `avisosVencidos()` la usa directamente: el mismo texto sale en la pestaña y en el tablero.

**Verificado contra la base**: las siete series de Comex —`PAGOS`, `PAGOS_PAGADOS`, `PAGOS_COMEX`, `PAGOS_TODO`, `NACIONALIZACION`, `NACIONALIZACION_PAGADAS` y `NACIONALIZACION_TODO`— dan **idénticas columna por columna** antes y después del cambio. Lo único que se movió son los avisos.

### Cómo se ve

- **Se esconde sólo con *Ver pagados***. Para el interruptor de vencidas esa fila ya no es vencida. Una cancelada sin tilde no tiene interruptor —sección 8— y queda a la vista con su marca de cancelada.
- **Sin badge rojo, y la fecha queda sola**, en la fila atenuada de pagada. El `title` de la vencida decía *"cargale la fecha nueva"*, que para un pago hecho es falso.
- **Las tarjetas no cambian**, y no por decisión de este cambio: salen de los totales del payload, que se arman con `IMPORTE_EJE`, y ahí una fila vencida o pagada ya valía cero.
- La regla CSS de *vencida y cancelada* se fue: esa combinación ya no existe.

### El aviso de lo marcado dice cuántos ya estaban vencidos

`avisosPagados()` —el del tablero— informa lo que el tilde sacó de la proyección, medido con `IMPORTE_PROYECTABLE`, que para una vencida es cero. Con casi todo lo tildado vencido, el aviso decía *"21 contenedores… $ 0,00"*, que se lee como un error. Ahora lo dice:

> *21 contenedor(es) tienen el nacionalización marcado como YA HECHO, así que salieron de la proyección: $ 0,00 que la fila del tablero ya no cuenta. Todos ya tenían la fecha vencida, así que no sumaban: marcarlos no movió ningún número.*

Y cuando son algunos: *"10 de ellos ya tenían la fecha vencida y no sumaban, así que no entran en ese importe"*.

---

## Lo que no cambió

- **La valuación con dólar futuro ROFEX**, fila por fila, según el mes de la fecha efectiva. Vive en `Comex::valuar()` y `DolarFuturo::resolver()`, y la leen la pestaña y el tablero: un solo `IMPORTE_ARS`. Ver el encabezado de `Class/DolarFuturo.php`.

  Lo que **sí** cambió es **quiénes la usan**: hasta `feature/comex-nac-usd` era sólo Proveedores Exterior, porque se creía que los gastos de nacionalización estaban en pesos. Ahora valúan las dos pestañas, cada una por su propia fecha efectiva. Ver la sección 6.
- **El override de cotización por contenedor** (`COTIZ_USD_EDIT`), y que **se descarta si el pago cambia de mes**. Lo único que cambió es de dónde sale la fecha anterior para compararla: antes de la tabla del cashflow, ahora del maestro. `Comex::descartaCotizacion()` no se tocó, y **sigue siendo de Proveedores Exterior solamente** —el motivo, que ya no es el que decía su docblock, está en la sección 6—.
- **Las tres vistas, las columnas fijas y el orden por encabezado.** Ver `README-cashflow.md`.

> **Un efecto lateral de ver los vencidos**: ahora hay contenedores cuya fecha de pago cae **antes** del inicio de la curva de futuros, así que se valúan aproximando con el primer mes que la curva tiene y quedan marcados. Pasaron de 0 a 16 contenedores aproximados. El aviso y la marca ya existían y no hubo que tocar nada: es exactamente el caso que `DolarFuturo::mesMasCercano()` ya cubría, incluido el tramo hacia atrás que hasta ahora no se usaba.

---

## Pruebas

```bash
php tests/run.php comex
```

- `tests/test_comex_dolar_futuro.php` — la valuación **de las dos pestañas**: qué cotización le toca a cada fila, el mes fuera de curva, el override y cuándo se descarta. Sin base.
- `tests/test_comex_fecha_maestra.php` — la fecha en el maestro, los vencidos y el tilde de pagado.
- `tests/test_comex_fecha_pago_manual.php` — la fecha de pago fijada a mano, y de dónde sale la marca de cada fecha.
- `tests/test_comex_saldo_pendiente.php` — lo de la sección 8.

De la sección 8, lo que se fija:

- **La regla del saldo, caso por caso**: sin pagos, parcial, cancelado, sobrepago y sin FOB, con los cuatro estados nombrados como los nombra Comercio Exterior. **Los números del contenedor 733 están escritos en la prueba**, y ése es el punto: son el ancla entre las dos aplicaciones, así que si esta prueba falla una de las dos se movió.
- **Los dos bordes de la tolerancia**: que medio centavo para cualquier lado siga siendo `CANCELADO`, que dos centavos ya no lo sean, y que el medio centavo de más **no dispare el aviso de sobrepago** — sin ese tope, la tolerancia declararía el contenedor cancelado y el aviso mandaría igual a corregir a mano una diferencia que la tolerancia ya declaró irrelevante.
- **Que el pendiente nunca sea negativo**, y que `pendiente + imputado = FOB` se cumpla también en el sobrepago: es lo que hace cerrar el invariante de series cuando hay plata cargada de más.
- **El invariante de tres partes sobre los ocho casos posibles**, que es el producto de las tres cosas que pueden pasarle a una fila: estar vencida, estar tildada y tener pagos en Comex. Se prueba **sin base**, con filas armadas a mano — que es el punto, porque en la base real hay dos contenedores con pagos y ninguno tildado.
- **Que una fila que repite un contenedor no aporte a ninguna de las cuatro series**, incluido `PAGOS_TODO`. Hoy **no hay ninguna OC hija en la base**, así que esto sólo se puede verificar acá. Y que la condición sea `!empty()` y no la inversa: con la condición invertida, *Crono Nacionalización* —que no trae ese campo— se habría ido entera a cero.
- **Que el cashflow no escriba la tabla de pagos**: se busca `INSERT`, `UPDATE` y `DELETE` sobre ella y las tres tienen que dar cero.
- **El cableado**: que la consulta resuelva la OC principal con `COALESCE`, que el FOB salga de ahí, que el `ROW_NUMBER` de la deduplicación no se vaya, que la valuación se pida sobre `PENDIENTE_USD` y que el aviso de valuación mida el pendiente y no el FOB.
- **Contra la base**, sólo lectura: que el 733 dé los tres números de Comercio Exterior, que su importe en pesos salga del pendiente, y que `FOB = pendiente + pagado` cierre **en las 76 filas del padrón**.

De lo nuevo, lo que se fija:

- **Las reglas puras**: `estaVencida()` (con hoy inyectado, para que la prueba no caduque sola), `marcaVigente()` y los dos avisos de `avisosVencidos()`, incluido el reparto entre lo que entra en la columna del mes en curso y lo que no.
- **El cableado**, leyendo archivos, con el mismo criterio de `test_tablas_controles.php`: que el filtro de embarque no vuelva, que las columnas `EDIT` no vuelvan a leerse, que el orden sea por la fecha efectiva con los nulos al final, que el endpoint de fechas sea uno solo y que el cliente no vuelva a mandar la fecha anterior.
- **Que el buscador y la celda no se copien**: que las dos pestañas deleguen en `Comex-fechas.js`, que ninguna reimplemente `sumarColumnas()` ni arme su propio `fetch`, y que las dos carguen el archivo compartido **antes** que el suyo.
- **El tilde de pagado**: que lo marcado no aporte al eje pero **siga siendo proyectable** —que es lo que hace cerrar el invariante—, y que el corte se cumpla en los **cuatro casos posibles** (nada / pagada / vencida / vencida y pagada). El corte de filas marcadas se prueba **sin base**, con una lista armada a mano, que es el punto: se puede verificar aunque no haya nada marcado en la base. Y que el registro declare las series con su `componentes`, que es lo que impide activar el universo y una parte a la vez — la lista de partes va escrita entera en la prueba, y por eso Proveedores Exterior tuvo que actualizarse cuando ganó la tercera. El invariante completo, con `PAGOS_COMEX`, se verifica en `test_comex_saldo_pendiente.php`.
- **El script**: que cree la tabla con las columnas que el código espera, que el índice único esté filtrado por `VIGENTE`, que no pise el maestro en conflicto y que sea reejecutable.
- **Que `alert()` no vuelva** a ninguno de los tres archivos, que los fallos vayan a `Notificacion.error()`, que la fecha guardada no avise igual cuando se descartó la cotización, y que la falla de carga se pinte adentro de `tableWrapper` **sin pisar la tabla** —si la pisara, *Actualizar* no tendría dónde dibujar—.
- **La valuación de Crono Nacionalización**: qué cotización le toca según la **fecha de nacionalización**, que el mismo contenedor se valúe distinto en cada pestaña porque sus dos fechas caen en meses distintos, el mes fuera de curva hacia adelante y hacia atrás, y que sin fecha el importe quede en `null` y no en cero. Y que **`valuar()` con los parámetros por defecto siga dando exactamente lo de hoy** para Proveedores Exterior: es la prueba que evita la regresión silenciosa —con los defaults invertidos, esa pestaña valuaría por un campo que no tiene y todos sus importes irían a cero sin que nada falle—.
- **Que la grilla no vuelva a ubicar `IMPORTE_EST` en el eje**, que la curva se lea una vez por listado y no una por fila, que ninguna serie de Comex declare pesos, que la celda de cotización no se reimplemente en ninguna de las dos pestañas y que la clave de columnas fijas haya cambiado con el layout.
- **Contra la base**, sólo lectura: que las dos consultas traigan el mismo padrón, que haya contenedores ya embarcados en la grilla, que el flag `VENCIDA` coincida con la regla pura fila por fila, que la fecha efectiva **sea** la del maestro y que el listado salga ordenado con los nulos al final.

De la sección 9, lo que se fija:

- **`vencidaPendiente()` caso por caso**: vencida sin pagar, tildada, cancelada sin tilde, con pago parcial, al día, y sin los campos de pago —que es como llega Crono Nacionalización—.
- **Que ninguna serie cambie**: sobre las **ocho** combinaciones de vencida × tildada × cancelada, los aportes a `PAGOS`, `PAGOS_PAGADOS`, `PAGOS_COMEX` y `PAGOS_TODO` dan lo mismo con el flag nuevo y sin él, y el invariante de tres partes cierra en las ocho. Y, dicho directo, que una vencida y pagada siga sin sumar al eje ni a `PAGOS_PAGADOS`.
- **Los dos avisos**: `avisosVencidos()` no cuenta tildadas ni canceladas y no avisa nada si están todas pagadas; `avisosPagados()` dice cuántas de las marcadas ya estaban vencidas, con los dos textos —*"N de ellos"* y *"Todos"*—.
- **El cableado de la pantalla**: que el contador, el filtro, el badge y el `data-vencida` de las dos pestañas lean `VENCIDA_PENDIENTE`, y que ninguno de los tres JS siga mirando `item.VENCIDA` a secas.
- **Contra la base**, sólo lectura: que toda fila traiga el flag y coincida con la regla pura, y que ninguna cancelada quede como vencida pendiente.

> **Las pruebas de cableado leen el código sin sus comentarios.** Estos archivos explican en prosa lo que dejaron de hacer —*"antes era `COALESCE(FECHA_PAGO_EDIT, ...)`"*— y esas notas son justamente lo que este módulo pide que se escriba. Buscar el patrón sobre el archivo entero daría positivo en la nota que dice que el patrón ya no está, y la única forma de pasar la prueba sería borrar la explicación.

---

## Archivos

```
sql/cashflow_comex_fecha_maestra.sql   La tabla del rastro y la migracion al maestro
sql/cashflow_comex_cotiz_edit.sql      El override de cotizacion por contenedor
sql/cashflow_comex_pagado.sql          Que pagos ya se hicieron, con su historial
cashflow/Class/Comex.php               Las dos consultas, el guardado y las reglas puras
cashflow/Class/DolarFuturo.php         La curva ROFEX y que cotizacion le toca a cada fila
cashflow/Class/Providers/ComexProvider.php   Las dos filas del tablero y sus siete series
cashflow/Class/CashflowRegistry.php          La moneda y las series de las dos filas
cashflow/Controller/ComexController.php      Listados, edicion de fechas y de cotizacion
cashflow/Js/Comex-fechas.js            Lo compartido por las dos pestanas: la celda de fecha
                                       editable, el tilde, la celda del dolar, el importe en
                                       pesos y el buscador
cashflow/Js/Comex-Proveedores_exterior.js
cashflow/Js/Comex-Crono_nacionalizacion.js
cashflow/Css/Comex-Proveedores_exterior.css
cashflow/Css/Comex-Crono_nacionalizacion.css
cashflow/Tabs/proveedores_exterior.php
cashflow/Tabs/crono_nacionalizacion.php
tests/test_comex_fecha_maestra.php
tests/test_comex_fecha_pago_manual.php  El BIT de fecha de pago fijada, y su degradacion
tests/test_comex_saldo_pendiente.php    La regla del saldo, el invariante de cuatro series
                                        y la deduplicacion de OC hijas
tests/test_comex_dolar_futuro.php
```

**La tabla de pagos NO tiene script**: es de Comercio Exterior y ya existe. Lo
unico que hace esta rama del lado de la base es LEERLA:

```
RO_T_IMPORTACIONES_ENCABEZADO_PAGOS    lo ya pagado a cada proveedor, en U$S
```

La regla del saldo que se calcula sobre ella vive replicada en
`Comex::saldoPendiente()` y su original en
`administracion/comercioExterior/class/Pagos.php::obtenerResumen()`. **Son dos
copias de la misma cuenta, en dos repos**, y el encabezado de cada una nombra a
la otra: es lo unico que hay para que no se separen.

El BIT lo crea un script del **otro** repo, porque la columna es del maestro de
Comercio Exterior:

```
administracion/comercioExterior/sql/10_fecha_pago_manual.sql
```

Se corre **después** de `sql/cashflow_comex_fecha_maestra.sql`: su backfill lee
`RO_T_CASHFLOW_COMEX_FECHA_EDIT` para marcar como fijadas las fechas que ya se
habían movido desde acá. Si esa tabla no existe, saltea el backfill con un
`PRINT` y no falla.

---

## Pendientes conocidos

- **El tilde de pagado no guarda CUÁNDO se pagó**, sólo cuándo se marcó. Nadie lo pidió y inventar una fecha de pago real que después nadie mantenga sería peor que no tenerla; si hace falta, es una columna más en `RO_T_CASHFLOW_COMEX_PAGADO`.
- **El historial de marcas se guarda pero no tiene pantalla**: la casilla muestra en su `title` quién marcó y cuándo, y `getHistorialPagado` devuelve la lista completa con las no vigentes. Mismo estado que el historial de fechas.
- **Sin login: el rastro se graba con `USUARIO = NULL`**, y la pestaña dice *"la movió desde el cashflow"* sin nombre. La costura ya está puesta: `guardarFecha()` recibe `$usuario` y el controller lo pasa. Es el mismo pendiente que el resto del módulo.
- **El historial se guarda pero todavía no se muestra entero.** La celda muestra el rastro **vigente** en su tooltip; `getHistorialFecha` devuelve la lista completa con las no vigentes y no hay pantalla que la pida. Es el mismo lugar en el que estuvo la exclusión de echeqs antes de su diálogo.
- **Dos ediciones huérfanas** en `RO_T_CASHFLOW_COMEX_CRONO_NAC` (`ID_MG` 558 y 560): apuntan a contenedores que ya no están en el maestro. No se migraron y el script las lista. No molestan a nadie —no aparecen en ningún join— pero alguien de Comercio Exterior tendría que decir si esos contenedores se dieron de baja a propósito.
- **La cotización de Crono Nacionalización no se puede corregir a mano.** Es una decisión y está explicada en la sección 6: el override es por contenedor y las dos pestañas lo mirarían en dos meses distintos. Tenerlo significaría **una columna más** en `RO_T_CASHFLOW_COMEX_CRONO_NAC` —la de la nacionalización, al lado de `COTIZ_USD_EDIT`— y **su propia regla de descarte**, atada al cambio de mes de la fecha de nacionalización y no al del pago. Nadie lo pidió todavía.
- **Los gastos de nacionalización siguen saliendo de `RO_T_IMPORTACIONES_ESTIMACION_DETALLE` con los conceptos 3 a 10 escritos en duro** en la consulta. Es anterior a este trabajo y nadie documentó de dónde sale ese rango.

  **El rango sí coincide con el de la pantalla de Comercio Exterior**, y desde `feature/fecha-pago-manual`. Hasta esa rama no: su *Total nacionalización* sumaba los conceptos **2 a 10** —`calcularTodosLosConceptos()` arrancaba por `seguro`— así que los dos sistemas informaban números distintos para el mismo contenedor, con el **Seguro** (`ID_CE = 2`) como única diferencia. En la OC `0000100015881`: 71.241,96 en la pantalla contra 71.200,76 acá, con el seguro en 41,20.

  Se corrigió del lado de la pantalla, no de acá, porque **el seguro se paga antes de nacionalizar** —junto con el flete, para poner la mercadería en el puerto de destino— y ya está contado dentro del CIF, que es la base sobre la que se calculan los impuestos que sí son de nacionalización. Sumarlo contaba dos veces el mismo concepto en dos roles distintos. La rama de Uruguay de esa misma función ya lo excluía, así que esta consulta era uno de los dos lugares que ya tenían razón.

- **Flete y Seguro no los proyecta ninguna pestaña.** Proveedores Exterior cubre el pago al proveedor por `VALOR_FOB_DOLAR` y Crono Nacionalización los conceptos 3 a 10; el flete (`ID_CE = 1`) y el seguro (`ID_CE = 2`) no entran en ninguna de las dos. Al 21/09/2026 son **U$S 208.560,00 y U$S 5.353,05** sobre 60 contenedores —en dólares, como todo lo que sale de esa estimación; ver la sección 6—. El seguro es despreciable, el flete no. Es anterior a este trabajo y nadie lo documentó; hace falta que alguien de Comercio Exterior diga si esos pagos salen por otro circuito antes de sumarlos al tablero.
- **No hay BIT equivalente para la fecha de nacionalización.** Quedó fuera de alcance a propósito: el problema que el BIT resuelve es específico de `FECHA_EST_PAGO`, que es la única fecha que el JS de Comercio Exterior vuelve a calcular sobre datos ya guardados. La de nacionalización ya queda protegida ahí por su propio flag al cargar.
- **El cálculo de +5 días sigue viviendo en el JS de Comercio Exterior**, duplicado en `Encabezado::DIAS_EMB_EST_PAGO` para que el endpoint de *volver a auto* pueda devolver la fecha resuelta. Moverlo al backend es lo que cerraría la duplicación y, de paso, haría deducible el BIT desde `RO_T_IMPORTACIONES_FECHAS_HIST` — hoy no lo es, porque el recálculo y la edición manual llegan por el mismo POST y dejan un rastro idéntico.
