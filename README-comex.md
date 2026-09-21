# Módulo Comercio Exterior — los pagos al exterior y la nacionalización

Pestañas **Comercio Exterior → Proveedores Exterior** y **Crono Nacionalización**, y las filas *Proveedores del Exterior* y *Nacionalizaciones* del tablero de Cashflow.

Ramas: `feature/comex-fecha-maestra` · `feature/comex-pagado`

---

## La idea en una línea

**Las dos pestañas son el mismo contenedor mirado desde los dos lados del circuito**, y las dos escriben sobre el mismo lugar. Qué se escribe dónde es la decisión de fondo de todo esto: **la fecha va al maestro de Comex porque es el mismo dato para las dos aplicaciones; el "ya se pagó" queda del lado del cashflow porque es una afirmación sobre su propia proyección.**

```
RO_T_IMPORTACIONES_ENCABEZADO  (maestro de Comercio Exterior)
        │  sin detalle cargado: el contenedor todavía no cerró
        │
        ├─ FECHA_EST_PAGO ──── cuándo se le paga al proveedor del exterior
        │       │  × curva de dólar futuro ROFEX del mes de esa fecha
        │       ▼
        │   Proveedores Exterior  →  ComexProvider (PAGOS / PAGOS_PAGADOS / PAGOS_TODO)
        │
        └─ FECHA_DESP_ADU ──── cuándo se nacionaliza
                │  + RO_T_IMPORTACIONES_ESTIMACION_DETALLE (conceptos 3 a 10)
                ▼
            Crono Nacionalización  →  ComexProvider (NACIONALIZACION / _PAGADAS / _TODO)

Del lado del cashflow:
  RO_T_CASHFLOW_COMEX_FECHA_EDIT   quién movió cada fecha del maestro desde acá
  RO_T_CASHFLOW_COMEX_PAGADO       qué pagos ya se hicieron, con su historial
  RO_T_CASHFLOW_COMEX_CRONO_NAC    el override de cotización por contenedor
```

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

**Crono Nacionalización la aplica igual**: una nacionalización con la fecha vencida tampoco suma. Es la misma función — `aporteAlEje()` recibe el campo de importe de cada pestaña (`IMPORTE_ARS` en una, `IMPORTE_EST` en la otra) en vez de tenerlo escrito adentro, que habría obligado a copiarla.

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

> `ComexFechas.verVencidas()` devuelve `true` cuando la pantalla **no** declara el control. La guarda sigue importando aunque hoy las dos lo tengan: sin ella, una pestaña nueva que dibujara filas con `data-vencida` abriría escondiéndolas sin ningún control que las traiga de vuelta, y nada lo diría.

### Cómo se ven en la grilla

Tres marcas, tres cosas distintas, y las tres las decide el backend:

| Marca | Qué dice |
| --- | --- |
| **Manual** (amarillo, sólo fecha de pago) | Esta fecha está **fijada a mano** y el recálculo automático de Comercio Exterior no la pisa. Sale del BIT `FECHA_PAGO_CONF` del maestro |
| **Editada** (naranja, sólo fecha de nacionalización) | Esta fecha del maestro la puso alguien desde el cashflow. El tooltip dice quién, cuándo y qué decía antes |
| **Vencida** (rojo) | La fecha ya pasó. El `title` explica la consecuencia: *"este importe no entra en ninguna columna del eje"*. En Proveedores Exterior además está escondida por defecto |
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
PAGOS + PAGOS_PAGADOS = PAGOS_TODO
NACIONALIZACION + NACIONALIZACION_PAGADAS = NACIONALIZACION_TODO
```

Mismo criterio que la exclusión de cheques de cartera: un importe que sale del tablero sin dejar rastro es un agujero que nadie puede auditar. `PAGOS` y `NACIONALIZACION` **cambian de significado y no de código** —pasan a ser *"lo que falta pagar"*—, así que **no hay que repuntar ninguna fila** ni tocar Parámetros. El día que se corre el script la tabla nace vacía y el tablero no se mueve un peso.

El proveedor informa en cada carga cuánto se marcó, con el conteo. Sin ese aviso, un egreso que el tablero debería estar proyectando desaparece y nada en pantalla lo explica.

**Cómo cierra el invariante**, que es la parte delicada:

| Serie | Campo | Sobre |
| --- | --- | --- |
| `PAGOS` | `IMPORTE_EJE` — vale cero si está pagado **o** vencido | todas las filas |
| `PAGOS_PAGADOS` | `IMPORTE_PROYECTABLE` — vale cero sólo si está vencido | las marcadas |
| `PAGOS_TODO` | `IMPORTE_PROYECTABLE` | todas |

Para una fila no marcada los dos campos valen lo mismo y aporta a `PAGOS`; para una marcada, `IMPORTE_EJE` es cero y aporta a `PAGOS_PAGADOS`. Por eso las dos reglas viven en funciones separadas (`importeProyectable()` y `aporteAlEje()`) en vez de en una sola.

**Verificado contra la base**, marcando una fila real en cada pestaña y deshaciéndolo:

| | Antes | Después | Diferencia | Importe de la fila |
| --- | --- | --- | --- | --- |
| `PAGOS` (contenedor 690) | $ 4.632.182.810 | $ 4.546.462.890 | **$ 85.719.920** | **$ 85.719.920** |
| `NACIONALIZACION` (contenedor 635) | $ 506.185,65 | $ 448.091,37 | **$ 58.094,28** | **$ 58.094,28** |

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

## Lo que no cambió

- **La valuación con dólar futuro ROFEX**, fila por fila, según el mes de la fecha efectiva de pago. Vive en `Comex::valuar()` y `DolarFuturo::resolver()`, y la leen la pestaña y el tablero: un solo `IMPORTE_ARS`. Ver el encabezado de `Class/DolarFuturo.php`.
- **El override de cotización por contenedor** (`COTIZ_USD_EDIT`), y que **se descarta si el pago cambia de mes**. Lo único que cambió es de dónde sale la fecha anterior para compararla: antes de la tabla del cashflow, ahora del maestro. `Comex::descartaCotizacion()` no se tocó.
- **Las tres vistas, las columnas fijas y el orden por encabezado.** Ver `README-cashflow.md`.

> **Un efecto lateral de ver los vencidos**: ahora hay contenedores cuya fecha de pago cae **antes** del inicio de la curva de futuros, así que se valúan aproximando con el primer mes que la curva tiene y quedan marcados. Pasaron de 0 a 16 contenedores aproximados. El aviso y la marca ya existían y no hubo que tocar nada: es exactamente el caso que `DolarFuturo::mesMasCercano()` ya cubría, incluido el tramo hacia atrás que hasta ahora no se usaba.

---

## Pruebas

```bash
php tests/run.php comex
```

- `tests/test_comex_dolar_futuro.php` — la valuación: qué cotización le toca a cada fila, el mes fuera de curva, el override y cuándo se descarta. Sin base.
- `tests/test_comex_fecha_maestra.php` — lo de esta rama.

De lo nuevo, lo que se fija:

- **Las reglas puras**: `estaVencida()` (con hoy inyectado, para que la prueba no caduque sola), `marcaVigente()` y los dos avisos de `avisosVencidos()`, incluido el reparto entre lo que entra en la columna del mes en curso y lo que no.
- **El cableado**, leyendo archivos, con el mismo criterio de `test_tablas_controles.php`: que el filtro de embarque no vuelva, que las columnas `EDIT` no vuelvan a leerse, que el orden sea por la fecha efectiva con los nulos al final, que el endpoint de fechas sea uno solo y que el cliente no vuelva a mandar la fecha anterior.
- **Que el buscador y la celda no se copien**: que las dos pestañas deleguen en `Comex-fechas.js`, que ninguna reimplemente `sumarColumnas()` ni arme su propio `fetch`, y que las dos carguen el archivo compartido **antes** que el suyo.
- **El tilde de pagado**: que lo marcado no aporte al eje pero **siga siendo proyectable** —que es lo que hace cerrar el invariante—, y que `PAGOS + PAGOS_PAGADOS = PAGOS_TODO` se cumpla en los **cuatro casos posibles** (nada / pagada / vencida / vencida y pagada). El corte de filas marcadas se prueba **sin base**, con una lista armada a mano, que es el punto: se puede verificar aunque no haya nada marcado en la base. Y que el registro declare las tres series con su `componentes`, que es lo que impide activar el universo y una parte a la vez.
- **El script**: que cree la tabla con las columnas que el código espera, que el índice único esté filtrado por `VIGENTE`, que no pise el maestro en conflicto y que sea reejecutable.
- **Contra la base**, sólo lectura: que las dos consultas traigan el mismo padrón, que haya contenedores ya embarcados en la grilla, que el flag `VENCIDA` coincida con la regla pura fila por fila, que la fecha efectiva **sea** la del maestro y que el listado salga ordenado con los nulos al final.

> **Las pruebas de cableado leen el código sin sus comentarios.** Estos archivos explican en prosa lo que dejaron de hacer —*"antes era `COALESCE(FECHA_PAGO_EDIT, ...)`"*— y esas notas son justamente lo que este módulo pide que se escriba. Buscar el patrón sobre el archivo entero daría positivo en la nota que dice que el patrón ya no está, y la única forma de pasar la prueba sería borrar la explicación.

---

## Archivos

```
sql/cashflow_comex_fecha_maestra.sql   La tabla del rastro y la migracion al maestro
sql/cashflow_comex_cotiz_edit.sql      El override de cotizacion por contenedor
sql/cashflow_comex_pagado.sql          Que pagos ya se hicieron, con su historial
cashflow/Class/Comex.php               Las dos consultas, el guardado y las reglas puras
cashflow/Class/DolarFuturo.php         La curva ROFEX y que cotizacion le toca a cada fila
cashflow/Class/Providers/ComexProvider.php   Las dos series del tablero
cashflow/Controller/ComexController.php      Listados, edicion de fechas y de cotizacion
cashflow/Js/Comex-fechas.js            La celda editable y el buscador, de las dos pestanas
cashflow/Js/Comex-Proveedores_exterior.js
cashflow/Js/Comex-Crono_nacionalizacion.js
cashflow/Css/Comex-Proveedores_exterior.css
cashflow/Css/Comex-Crono_nacionalizacion.css
cashflow/Tabs/proveedores_exterior.php
cashflow/Tabs/crono_nacionalizacion.php
tests/test_comex_fecha_maestra.php
tests/test_comex_fecha_pago_manual.php  El BIT de fecha de pago fijada, y su degradacion
tests/test_comex_dolar_futuro.php
```

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
- **Los gastos de nacionalización siguen saliendo de `RO_T_IMPORTACIONES_ESTIMACION_DETALLE` con los conceptos 3 a 10 escritos en duro** en la consulta. Es anterior a este trabajo y nadie documentó de dónde sale ese rango.

  **Y ese rango no es el mismo que usa la pantalla de Comercio Exterior.** Su *Total nacionalización* suma los conceptos **2 a 10** — `calcularTodosLosConceptos()` en `js/editar-estimacion.js` arranca por `seguro`—, así que los dos sistemas informan dos números distintos para el mismo contenedor y **la diferencia es exactamente el Seguro** (`ID_CE = 2`). Ejemplo verificado al 21/09/2026, OC `0000100015881`: la pantalla muestra 71.241,96 y el cashflow suma 71.200,76, con el seguro en 41,20.

  Sobre el padrón entero son **5.353,05 de seguro sobre 4.630.245,72**, o sea que el cashflow proyecta **0,116% de menos**. Es chico pero no es cero, y sobre todo hace que los dos tableros no se puedan contrastar a ojo.

  No se toca acá porque decidir cuál de los dos tiene razón es una pregunta de negocio: el seguro se paga afuera y se suma al CIF, así que puede argumentarse que no es un gasto de nacionalización. Quien lo resuelva tiene que cambiar **uno** de los dos lados, no los dos.
- **No hay BIT equivalente para la fecha de nacionalización.** Quedó fuera de alcance a propósito: el problema que el BIT resuelve es específico de `FECHA_EST_PAGO`, que es la única fecha que el JS de Comercio Exterior vuelve a calcular sobre datos ya guardados. La de nacionalización ya queda protegida ahí por su propio flag al cargar.
- **El cálculo de +5 días sigue viviendo en el JS de Comercio Exterior**, duplicado en `Encabezado::DIAS_EMB_EST_PAGO` para que el endpoint de *volver a auto* pueda devolver la fecha resuelta. Moverlo al backend es lo que cerraría la duplicación y, de paso, haría deducible el BIT desde `RO_T_IMPORTACIONES_FECHAS_HIST` — hoy no lo es, porque el recálculo y la edición manual llegan por el mismo POST y dejan un rastro idéntico.
- **Las dos pestañas siguen usando `alert()`** en vez de `Js/notificaciones.js`. Está en la lista de `README-cashflow.md`; cambiarlo no es parte de esta etapa y mezclarlo habría metido acá un archivo que no tiene nada que ver.
