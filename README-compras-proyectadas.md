# Módulo Compras Exterior — lo que todavía no tiene contenedor

Pestaña **Comercio Exterior → Proyección**, y las filas *Proveedores Exterior Proyectado* y *Nacionalizaciones Proyectado* del tablero de Cashflow.

Rama: `feature/comex-compras-proyectadas`

> **Nombre visible y código interno.** En pantalla el módulo se llama *Compras Exterior* (sub-pestaña de Parámetros, módulo del registro, prefijo de los avisos) y su pestaña *Proyección*. **El código interno sigue siendo `COMPRAS_PROY` / `compras_proyectadas`**: claves de parámetros, `GRUPO` de las filas, clases, archivos y claves de `localStorage` no cambiaron, para no romper parámetros guardados ni estados.

---

## La idea en una línea

**Comercio Exterior sabe lo que ya se compró; la app de compras sabe lo que falta comprar. Este módulo proyecta la diferencia, y la ubica en el mes en que se va a pagar.**

```
POWER_BI_CONTROL (app de compras)          central (Tango)
  RO_V_COMPRA_PROYECTADA_VIGENTE             CPA35 + STA20
  el presupuesto OFICIAL de cada               las recepciones de
  temporada, tramo objetivo                    importación, históricas
        │                                            │
        │  FOB U$S = compra × costo_prom             │  % del año que
        │                                            │  entra en cada mes
        ▼                                            ▼
   ┌────────────────────────────────────────────────────────┐
   │  reparto: cada temporada entre sus 6 meses de recepción │
   └────────────────────────────────────────────────────────┘
        │
        │  menos lo YA COMPRADO, sobre el eje de PAGO
        │  ┌──────────────────────────────────────────────┐
        └──┤ RO_T_IMPORTACIONES_ENCABEZADO (maestro Comex)│
           │ contenedores con OC emitida DESPUÉS de la    │
           │ fecha de cálculo de la versión de SU temporada│
           └──────────────────────────────────────────────┘
        │
        ▼
   estimación del mes = MAX(0, proyectado − cargado)
        │
        ├─ × dólar futuro ROFEX del mes de PAGO ──────► PAGOS_PROYECTADOS
        │                                                (día D − X días)
        │
        └─ × % de nacionalización, y × ROFEX del mes ──► NACIONALIZACION_PROYECTADA
           de nacionalización                            (día D − Y días)
```

**Nunca se suma a las series de `ComexProvider`.** Son dos universos que no se pisan: Comex trae lo que **ya tiene contenedor cargado**, ubicado contenedor por contenedor con las fechas del maestro; este módulo trae lo que **todavía no**. En el tablero conviven como dos partes de un mismo renglón.

---

## Ejecución de los scripts

Contra `central`:

| # | Script | Qué hace | Si no se corre |
| --- | --- | --- | --- |
| 1 | `sql/cashflow_estructura_grupos.sql` | *(ya existía)* Agrega `GRUPO`, `NATURALEZA` y `GRUPO_NOMBRE` | Las dos filas nuevas se crean igual, **sueltas**, y el script lo avisa. El tablero muestra cuatro filas en vez de dos renglones que se abren. **Ningún importe cambia**: la agrupación es presentación |
| 2 | `sql/cashflow_compras_proyectadas.sql` | Crea las dos filas del tablero, asigna los dos grupos, crea `RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE` y siembra los siete parámetros | La pestaña avisa y no rompe. Las dos filas no existen, así que **el tablero queda exactamente como hoy**, sin parte proyectada y sin ningún número cambiado |
| 3 | `sql/cashflow_comex_materializado.sql` | Crea `RO_T_CASHFLOW_JOB_LOG`, `RO_T_CASHFLOW_COMEX_RECEP_HIST`, `RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN` y `RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE` | **Las dos filas van en CERO** y el primer aviso dice qué job falta. Ver la sección 10 |
| 4 | `sql/RO_SP_CASHFLOW_COMEX_RECEP_HIST.sql` | El SP de la historia de recepciones, con la programación sugerida al pie | Historia vacía: filas en cero, avisando |
| 5 | `sql/RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN.sql` | El SP del presupuesto oficial y el contraste, por `[XL-APPS]`, con la programación sugerida al pie | Presupuesto vacío: filas en cero, avisando |

Después de los scripts 4 y 5 hay que **correr los dos SP una vez** (o apretar *Actualizar ahora* en la pestaña) y **crear los jobs** del SQL Agent. Los jobs no los crea ningún script.

Y en el repo **compras**, contra `POWER_BI_CONTROL`:

| Script | Qué hace | Si no se corre |
| --- | --- | --- |
| `presupuestos/sql/05_baja_logica_versiones.sql`, bloque 4 | Crea `RO_V_COMPRA_PROYECTADA_VIGENTE` | **Las dos filas van en CERO** y el aviso lo dice primero. Es la única degradación del módulo que cambia números en vez de apagar un botón |

El script 2 es **reejecutable**: cada inserción pregunta por su código, cada parámetro por su clave, cada tabla por `OBJECT_ID`, y cada `UPDATE` sólo toca lo que todavía no está puesto.

> **Además corrige el `GRUPO` y el `TIPO_DATO` de un parámetro que ya existía**, y hace falta. Una versión anterior sembraba los tres de días con `GRUPO = 'FECHAS'`, y *Parámetros* resuelve su sección pidiendo `'GENERAL'`: esos tres **existían y no se dibujaban**. Como el `INSERT` sólo crea lo que falta, sin ese `UPDATE` hubieran quedado invisibles para siempre.

### Después de correrlos, verificar

- `PROV_EXTERIOR_PROY` y `NACIONALIZACIONES_PROY` **activas**, en los órdenes 15 y 25, cada una **pegada** a su parte real. El agrupamiento es posicional: una fila activa en el medio deshace el grupo, y el validador lo avisa.
- Los dos grupos con **una fila `REAL` y una `PROYECTADO`** cada uno. Se verifica con `CashflowEstructura::grupos()`, y la estructura tiene que dar `VALIDO: true`.
- Los siete parámetros en `GRUPO = 'GENERAL'` y `MODULO = 'COMPRAS_PROY'`, o la pestaña *Parámetros* no los dibuja.

> **Verificado contra la base el 23/09/2026**: con las dos filas activas, `CashflowEstructura::validar()` no devuelve ningún error nuevo, y los dos grupos se arman consecutivos.

---

## 1. Las tres reglas puras

Viven en `cashflow/Class/ComprasProyectadas.php`, **sin base y sin reloj**: hoy y el fin del horizonte entran por parámetro. Es lo que permite fijar en una prueba los casos que en la base real no se pueden producir a voluntad.

### Temporada

La convención es la de la app de compras, y es **una sola** en las dos aplicaciones:

| Temporada | Formato | Período |
| --- | --- | --- |
| Verano | `VER AA-AA` | 01/08 al 31/01 |
| Invierno | `INV AA` | 01/02 al 31/07 |

**Enero es la trampa, y es la única.** El verano arranca en agosto y termina el 31 de enero del año siguiente, así que **enero de 2027 es `VER 26-27`, no `VER 27-28`**. Resuelto por el año calendario, toda la compra de enero buscaría el presupuesto de una temporada que todavía no empezó: o no lo encuentra —y el mes va en cero— o encuentra el de la siguiente y proyecta plata de otra compra. Las dos cosas dan un tablero que cierra igual.

El **código** que arma `armarTemporada()` es la clave contra la app de compras: es el mismo texto que guarda `temporada_codigo` en la versión oficial. Un formato distinto de este lado deja a la temporada sin encontrar su versión y al mes entero en `SIN_PRESUPUESTO`, sin que nada falle. Por eso se arma en un solo lugar.

### Cuota

Qué porcentaje de la compra de una temporada se recibe en cada uno de sus seis meses, medido sobre la historia real de recepciones de importación.

**Se normaliza POR TEMPORADA y no por año.** Lo que el presupuesto da es la compra de **una** temporada, así que sus seis meses tienen que sumar 100 %. Repartir con los porcentajes del año dejaría sin asignar el 42 % que se lleva la otra temporada, y esa plata desaparecería del tablero sin que ninguna suma lo delate.

**Los años se SUMAN, no se promedian.** Promediar los porcentajes de cada año le daría el mismo peso a un año de 760.000 unidades que a uno de 1.320.000. La contracara es que un año anómalo pesa más, y por eso el parámetro son **tres** años: año por año la cuota es visiblemente inestable.

| | 2023 | 2024 | 2025 |
| --- | ---: | ---: | ---: |
| Abril, dentro del invierno | **23,66 %** | 15,22 % | **11,10 %** |
| Septiembre, dentro del verano | 22,71 % | **30,15 %** | **13,63 %** |

Agregada sobre los tres, en cambio, se estabiliza: contra una ventana móvil de 36 meses, la cuota normalizada se mueve menos de 1,5 puntos en diez de los doce meses.

**El peso es el IMPORTE, prorrateado.** Una orden de compra se recibe en varias tandas: medido contra la base, 55 órdenes de 1.317 tienen más de una fecha de movimiento y una llega a tener cinco. Si cada fecha arrastrara el `TOTAL_EXT` entero de su orden, el importe se contaría una vez por tanda: sobre 2023-2025 son **18,50 % de más**, y no repartido parejo sino concentrado en las órdenes grandes, que son justamente las que más mueven la cuota. Mayo pasaría de pesar 7,78 % a 12,99 %.

```
importe del movimiento = TOTAL_EXT de la orden × cantidad del movimiento / cantidad total
```

Se puede repartir por **unidades** en vez de por importe (`compras_proy_base_cuota`). Las dos difieren hasta 4,5 puntos en un mes, porque el precio por unidad no es parejo. Se reparte plata, así que manda el importe.

**Un mes sin ningún movimiento no es un cero.** Si en los N años no hay ni un registro de ese mes calendario, no se sabe cuánto entra: el mes queda en `null` —no en cero— y sale marcado `SIN_HISTORIA`. El reparto se normaliza sobre los meses que **sí** tienen historia, así la temporada reparte el 100 % de su presupuesto igual. Un mes que aparece con peso **cero** sí es un cero: es un mes en el que efectivamente no entró nada, y eso es información.

### Ventana

**El último mes NO es el último del horizonte, y ésa es toda la regla.** Lo que el tablero muestra es el **pago**, que ocurre X días **antes** de la recepción. Con X = 47, una recepción del 15/10 se paga el 29/08: cortar la ventana en el último mes del horizonte dejaría afuera dos meses de pago que el cuadro **sí** puede mostrar.

Así que el último mes se **busca**: se avanza mes a mes mientras el pago siga cayendo adentro del horizonte. Se deriva de `D`, de `X` y del fin del eje, que son los tres editables desde *Parámetros*; fijarlo en una constante lo dejaría mintiendo el día que alguien mueva cualquiera de los tres.

Con los parámetros de hoy —eje hasta el 31/08/2027, `D = 15`, `X = 47`— el último mes es **2027-10** y no 2027-08.

Dos cosas que es fácil leer al revés, y que están escritas como pruebas:

- **Un `X` grande AGRANDA la ventana**, no la achica: el pago va antes, así que entran meses de recepción más lejanos cuyo pago sigue cayendo adentro.
- **Un `D` más tarde la achica**: empuja el pago más tarde, así que entran menos meses.

**El día D se recorta al largo del mes.** Con `D = 31` y febrero, componer la fecha sin recortar daría el 2 o 3 de marzo: el mes de recepción se correría solo y con él podría cambiar la temporada, que es lo que decide contra qué presupuesto se proyecta.

---

## 2. El descuento de lo ya comprado

### Cada temporada contra la fecha de SU versión

No hay una fecha de corte global. El presupuesto de una versión ya es **neto** de las órdenes pendientes al momento de calcularlo —el `stock_proyectado` de la app de compras incluye `CANT_PEND_OC`—, así que restar un contenedor cuya OC se emitió **antes** de esa fecha lo cuenta dos veces.

Y como la ventana cruza dos o tres temporadas, **una sola fecha de corte equivocaría a todas menos una**.

### La resta va sobre el eje de PAGO

Lo proyectado nace en el eje de **recepción** —la cuota reparte meses de recepción— pero un contenedor del maestro se ubica por su **`FECHA_EST_PAGO`**, que es lo que hace la pestaña *Proveedores Exterior*. Es el único eje en el que los dos lados están definidos.

**No hay fórmula que convierta un eje en el otro.** La cadena de Comercio Exterior dice que la nacionalización va 45 días después del pago, pero eso es sólo el valor por defecto: las fechas del maestro están editadas a mano una por una. Medido contra la base:

| Distancia real entre `FECHA_EST_PAGO` y `FECHA_DESP_ADU` | Contenedores |
| --- | ---: |
| 41 a 49 días (lo que dice la cadena) | 39 |
| 1 a 36 días | 17 |
| **negativa** — se nacionaliza *antes* de pagarse | **13** |

Un mes de pago reparte sus contenedores en hasta **cuatro** meses de recepción distintos.

### Lo cargado se consume una sola vez

Si dos meses de recepción caen en el mismo mes de pago —pasa según `D` y `X`— se reparte en orden cronológico: cada mes toma lo que puede hasta su proyectado y el siguiente recibe el resto. Y sólo de los contenedores que **ese mes tenía derecho a usar**: dos meses que comparten mes de pago pueden ser de temporadas distintas, con cortes distintos.

Con `D = 15` y `X = 47` el mapeo es uno a uno, **pero no es una propiedad de la regla**: con `X = 44` dos recepciones caen en el mismo mes de pago, y con `X = 45` febrero no paga nada.

### Un exceso no se compensa

Si lo cargado supera a lo proyectado, la estimación es **cero y nunca negativa**, y el sobrante se informa aparte en U$S. Un egreso negativo sería un ingreso que nadie afirmó, y encima compensado en silencio contra el resto de la columna. Es la misma regla que `Comex::saldoPendiente()` con el sobrepago.

### Lo que no se puede ubicar, no descuenta

| Caso | Qué hace |
| --- | --- |
| Sin `FECHA_EST_PAGO` | No descuenta en ningún mes. Elegirle uno sería inventar el dato que falta. Al 23/09/2026 son **4 contenedores, U$S 164.526,47** |
| Sin OC en Tango | No descuenta: el corte es *"emitida después de la fecha de cálculo"*, y de una OC que no está no se puede afirmar eso |
| Mes de pago fuera de la ventana | No descuenta. Va como **nota de reconciliación**, no como aviso |

### Hoy el descuento da cero, y por el motivo correcto

Las dos versiones oficiales se calcularon el 22 y el 23/09/2026, y la OC de importación más nueva cargada en Comex se emitió el **13/08/2026**. Así que ningún contenedor pasa el corte.

Eso **no** significa que el mecanismo esté dormido. Los meses 2027-02, 03 y 04 tienen 24 contenedores por U$S 1.512.205 de FOB pendiente, pertenecen a `INV 27` —que sí tiene oficial— y la regla los deja afuera **correctamente**: sus OC son anteriores al 23/09, así que ya están descontadas dentro del presupuesto.

Con la fecha de corte de la versión anterior de invierno (23/07/2026), el mecanismo descontaría 28 contenedores por U$S 1.636.696.

---

## 3. La nacionalización no usa `inc_fob`, y es la decisión más discutible del módulo

La versión oficial trae un `inc_fob` por fila, y sería lo natural. **No sirve.**

| | mín | ponderado | máx |
| --- | ---: | ---: | ---: |
| Cociente nacionalización / FOB de los contenedores **reales** (59 con FOB ≥ 20.000) | **0,707** | **0,892** | **1,059** |
| `inc_fob / 100` del presupuesto | **0,000** | **0,414** | **0,500** |

`inc_fob` toma **dos valores en toda la versión: 0 y 50**. Ninguna de las 60 filas cae dentro del rango real, y el **30,4 % del FOB** —U$S 1.741.337,60: carteras de cuero, ojotas, cosmética, lentes, relojes— tiene `inc_fob = 0`, o sea que proyectaría nacionalización **cero**.

Es coherente que no coincidan: `inc_fob` es el **incremento comercial sobre el FOB** que usa el circuito de distribución para costear (`vcosto = costo_prom × (1 + inc_fob/100)` en las 60 filas), no la carga impositiva de nacionalizar. Los gastos reales son los conceptos 3 a 10 de la estimación de Comex, que se calculan como porcentajes del CIF — ver la sección 6 de `README-comex.md`.

Con `inc_fob`, la ventana de hoy proyectaría **U$S 1,99 M** de nacionalización donde los contenedores reales indican **U$S 5,11 M**.

**Entonces se usa un parámetro propio del cashflow**, `compras_proy_nac_pct`, con valor inicial **89 %**. El `inc_fob` se sigue leyendo y se muestra en la pestaña, fila por fila, al lado del porcentaje que se aplica: si alguna vez se acercan, esa columna es donde se vería primero.

### Y se deriva del FOB ya descontado, no se descuenta aparte

Verificado contra la base: de 28 contenedores ordenados después de una fecha de cálculo, **27 no tienen `IMPORTE_EST` cargado**. La estimación de gastos se carga más tarde en el circuito de Comercio Exterior.

Descontar la nacionalización contra esa estimación restaría casi cero hoy y saltaría de golpe el día que alguien la cargue, moviendo el tablero sin que haya cambiado ninguna compra. Derivándola del FOB descontado queda consistente: lo que *Crono Nacionalización* empiece a mostrar de esos contenedores es lo mismo que esta fila dejó de mostrar.

> **El costo, y está asumido**: ese pedazo de nacionalización queda ubicado en el mes que deriva de la cuota y no en el `FECHA_DESP_ADU` real del contenedor, que con distancias de −60 a +68 días puede ser otro mes.

---

## 4. Los estados de cobertura

**El motor nunca deja un mes de la ventana en cero sin decir por qué.** El tablero muestra un número; lo que no puede mostrar es cuál de cinco motivos distintos produjo ese cero. Por eso el estado es una **columna** de la grilla y no un tooltip.

| Estado | Qué significa |
| --- | --- |
| `ESTIMADO` | Tiene versión oficial, cuota y cotización |
| `AJUSTADO` | Un importe cargado a mano reemplaza la estimación |
| `CUBIERTO` | Lo ya comprado alcanza o supera lo proyectado. La estimación es cero, y el exceso se informa en U$S |
| `SIN_COTIZACION` | El mes de pago no está en la curva de futuros: se valuó con el mes más cercano y quedó marcado |
| `SIN_HISTORIA` | La cuota no tiene datos para ese mes calendario |
| `SIN_PRESUPUESTO` | La temporada del mes no tiene versión oficial. **Se está proyectando de menos** |
| `AJUSTE_DESCARTADO` | Tenía un ajuste manual, y cambió la versión oficial de su temporada |

**Gana el primero de esa lista**, y el orden *es* la regla:

- `SIN_PRESUPUESTO` va primero porque sin versión no hay nada que calcular: ni cuota que aplicar ni cargado que descontar. Cualquier otro estado describiría una cuenta que no se hizo.
- `AJUSTE_DESCARTADO` va antes que `AJUSTADO` por lo mismo que existe: alguien cargó un número y el sistema decidió no usarlo. Tapado por el estado de la estimación automática, el mes se vería normal y nadie se enteraría.
- `AJUSTADO` va antes que todo lo que sigue porque **reemplaza** la estimación: que la cuota no tenga historia o que lo cargado cubra el mes deja de importar cuando el importe lo puso una persona.
- `SIN_COTIZACION` **no** gana sobre `CUBIERTO`: un mes cubierto vale cero, y cero por cualquier cotización es cero, así que no hay aproximación que informar.

Lo que no ganó el estado viaja en `marcas`, así que no se pierde ninguno.

### Los avisos van en dos listas, y la diferencia es a quién le hablan

| | |
| --- | --- |
| `warnings` | Suben al **tablero**. Dicen que la proyección está **incompleta** y por qué: una temporada sin presupuesto, un mes sin historia, un ajuste descartado, un exceso |
| `notas` | Se quedan en la **pestaña**. Explican por qué el número no coincide con otra pantalla |

Van separadas porque la más común de las notas —los contenedores que se pagan fuera de la ventana— aparece **siempre**, y al 23/09/2026 con 67 de 71 contenedores adentro. Mezclada con los avisos, enseña a ignorar el bloque entero, que es la única forma de que un aviso que sí importa pase desapercibido.

---

## 5. El ajuste manual por mes

Un importe en U$S FOB, cargado a mano, que **reemplaza** la estimación automática de un mes. La nacionalización se recalcula sobre el importe ajustado.

**Qué no es**: una corrección de la cuota ni del presupuesto. Si lo que está mal es el reparto entre meses, se tocan los parámetros; si lo que está mal es el total de la temporada, se guarda otra versión oficial en la app de compras. El ajuste existe para lo que ninguno de los dos puede saber: una compra puntual que alguien de Comercio Exterior ya sabe que entra en ese mes y que el presupuesto no refleja.

### La versión la resuelve el servidor, y es toda la regla

El ajuste queda atado a la versión oficial de la temporada de ese mes, y ese id lo **busca el servidor**. Es lo que permite descartarlo solo cuando la oficial cambia: si el cliente lo mandara, bastaría con mandar el id nuevo para que un número viejo siguiera aplicándose contra un presupuesto que no miró nunca. Mismo criterio que `Comex::descartaCotizacion()`, que tira el override cuando el pago cambia de mes.

**Y por eso un mes sin versión oficial no se puede ajustar**: no habría a qué atarlo, y quedaría aplicándose para siempre sobre una temporada que nadie presupuestó. El botón se dibuja deshabilitado diciendo por qué, y el servidor lo rechaza igual.

Al descartarse, el mes **vuelve a la estimación automática y no a cero**: descartar el ajuste no puede hacer desaparecer el egreso, sólo dejar de pisarlo.

### Sin bajas físicas

Corregir un ajuste marca `VIGENTE = 0` el anterior e **inserta** uno nuevo. Con un `UPDATE`, un dedazo corregido a los cinco minutos y una decisión que estuvo vigente tres semanas son indistinguibles después del hecho. Mismo criterio que `RO_T_CASHFLOW_ECHEQ_EXCLUIDO`.

La baja y el alta van en la **misma transacción**: el índice único filtrado por `VIGENTE` prohíbe dos vigentes del mismo mes, así que separadas, un fallo de la segunda dejaría el mes sin ajuste y con el anterior dado de baja.

### Lo que se valida, y por qué

| Regla | Por qué |
| --- | --- |
| `AAAA-MM` | — |
| El mes tiene que estar **en la ventana** | Fuera de ella se guardaría bien y no cambiaría ningún importe: quien lo cargó creería haber movido algo |
| El importe **puede ser cero** | Significa *"este mes no se compra nada"*, que es distinto de no tener ajuste |
| El importe **no puede ser negativo** | Sería un ingreso que nadie afirmó |
| El motivo es **obligatorio** | Meses después es lo único que explica por qué ese mes no muestra la estimación automática. Mismo criterio que la exclusión de cheques |

Los errores se **acumulan** en vez de cortar en el primero: quien carga el formulario tiene que poder arreglar todo de una vez. Y la ventana la resuelve el servidor: el endpoint es alcanzable sin pasar por la grilla.

---

## 6. Los parámetros

Módulo **`COMPRAS_PROY`** en *Parámetros*, sub-pestaña *Compras Exterior*.

| Clave | Inicial | Qué decide |
| --- | ---: | --- |
| `compras_proy_meses` | 6 | Cuántos meses de recepción se proyectan. **El último no lo fija este número**: se deriva solo |
| `compras_proy_anios_cuota` | 3 | Años calendario **completos** de historia. El año en curso está a medias y le daría a los meses transcurridos un peso que los que faltan no pueden compensar |
| `compras_proy_base_cuota` | `IMPORTE` | `IMPORTE` (FOB prorrateado) o `UNIDADES` |
| `compras_proy_nac_pct` | 89 | Porcentaje de nacionalización sobre el FOB. Ver la sección 3 |
| `compras_proy_dia_llegada` | 15 | Día del mes en que se ubica la recepción |
| `compras_proy_dias_pago` | 47 | Días entre el pago del FOB y la recepción |
| `compras_proy_dias_nac` | 2 | Días entre la nacionalización y la recepción |

### Los tres de días son del cashflow, y no leen la cadena de Comex

Arrancan con el valor que hoy da `RO_T_IMPORTACIONES_PARAM_CRONOGRAMA` —embarque + 5 al pago, embarque + 52 a la recepción, o sea 47 y 2— **pero no la leen**. Si allá cambian los días, acá no cambia nada hasta que alguien lo decida.

Es a propósito. Los contenedores reales tienen sus fechas editadas a mano una por una, así que esa cadena es un valor por defecto y no una regla. Atar la proyección a ella haría que un ajuste operativo de la otra aplicación corra plata de mes en el tablero de finanzas sin aviso.

La pantalla **dibuja la cadena** con los valores cargados, porque "día 15", "47 días" y "2 días" son tres números que no se leen sueltos — y esconden una trampa: **subir los días de pago corre el egreso hacia atrás en el eje**, no hacia adelante.

### Los valores iniciales están escritos dos veces, a propósito

En el script y en `ComprasProyectadasProvider::DEFAULTS`. Sin el script, la fila tiene que seguir dando un número razonable y avisando, en vez de tumbar el módulo con *"Falta el parámetro"* —que es lo que hace `Parametros::num()`—. Hay una prueba que exige que los dos juegos coincidan: si se separan, una instalación con el script corrido y otra sin correr proyectan números distintos y nada lo dice.

---

## 7. El filtro de la vista, que el cashflow no controla

`RO_V_COMPRA_PROYECTADA_VIGENTE` tiene hoy un `WHERE` que el script del repo **compras** no tiene:

```sql
AND d.rubro NOT LIKE '%cuero%'
```

Es **deliberado** —decisión del área— y deja afuera, sobre el tramo objetivo de las dos oficiales, **34 filas por U$S 2.196.094,90 de FOB**: carteras, cintos, billeteras y accesorios de cuero.

Desde el cashflow ese filtro es **invisible**: la vista devuelve 55 filas y nada dice que en las tablas hay 72. Por eso `ComprasProyectadasDatos::contrasteVista()` compara la vista contra sus tablas de origen y la pestaña lo informa. El control no opina sobre el filtro; hace que la diferencia se vea.

> **Pendiente del lado del repo compras**: el filtro vive **sólo en la base**. El bloque 4 del `05_baja_logica_versiones.sql` usa `CREATE OR ALTER`, así que la próxima corrida de ese script lo borra en silencio y el tablero empieza a proyectar esos U$S 2,2 M sin que nadie lo decida. Hay que bajarlo al script allá.

---

## 8. Qué se ve en la pestaña

- **La grilla de cobertura**: un renglón por mes de recepción, con su temporada, su versión, su **estado**, la cuota, lo proyectado, lo ya comprado, la estimación, y las dos fechas con su cotización y su importe en pesos.
- **Las versiones oficiales**, con el `inc_fob` del presupuesto al lado del porcentaje que el cashflow aplica, fila por fila.
- **El detalle por rubro** de cada versión, en un modal. Las filas **sin costo** se marcan en vez de multiplicarse por cero: un `NULL` tratado como cero es una afirmación —*"esa mercadería no cuesta nada"*— que nadie hizo. En la base hay dos por versión.
- **Cuatro KPIs**. El exceso se dice en el pie de *Ya comprado* y no se resta de ningún lado.
- **El ajuste manual** por mes, con su historial completo.

La pestaña **no calcula nada**: le pide la grilla al mismo proveedor que alimenta al tablero. Con la cuenta en los dos lados, la pestaña y el tablero podrían mostrar dos estimaciones distintas del mismo mes y nadie podría decir cuál vale. Es la misma decisión que tomó Comercio Exterior cuando la valuación se mudó al getter.

> El controller pide las **series antes que la grilla**, y no al revés: `series()` es lo que acumula los avisos del proveedor. Pidiendo la grilla primero, la pestaña diría menos que el tablero sobre exactamente los mismos números.

---

## 9. Cuánto pone en el tablero

Verificado contra la base el **23/09/2026**, con las dos filas activas:

| recepción | pago | temporada | versión | cuota | proyectado U$S | cargado | estimación U$S | estado |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | --- |
| 2027-05 | 2027-03-29 | INV 27 | 11 | 16,12 % | 414.066,84 | 0,00 | 414.066,84 | `ESTIMADO` |
| 2027-06 | 2027-04-29 | INV 27 | 11 | 13,00 % | 333.902,92 | 0,00 | 333.902,92 | `ESTIMADO` |
| 2027-07 | 2027-05-29 | INV 27 | 11 | 11,33 % | 290.849,72 | 0,00 | 290.849,72 | `ESTIMADO` |
| 2027-08 | 2027-06-29 | VER 27-28 | 9 | 21,41 % | 997.249,65 | 0,00 | 997.249,65 | `ESTIMADO` |
| 2027-09 | 2027-07-30 | VER 27-28 | 9 | 20,53 % | 956.237,35 | 0,00 | 956.237,35 | `ESTIMADO` |
| 2027-10 | 2027-08-29 | VER 27-28 | 9 | 22,08 % | 1.028.634,02 | 0,00 | 1.028.634,02 | `ESTIMADO` |
| | | | | | | | **4.020.940,50** | |

Nacionalización al 89 %: **U$S 3.578.637,05**.

En pesos, dentro del horizonte:

| Fila | |
| --- | ---: |
| *Proveedores Exterior Proyectado* | **$ 7.222.122.497,04** |
| *Nacionalizaciones Proyectado* | **$ 3.298.271.863,26** |

### La nacionalización deja más de la mitad fuera del eje, y es estructural

**$ 3.281.339.738,41** de la nacionalización proyectada caen **fuera del horizonte**. No es un dato mal cargado: la ventana se corta con el último mes cuyo **pago** entra en el eje, y la nacionalización va sólo 2 días antes de la recepción contra los 47 del pago. Los dos últimos meses de la ventana aportan su FOB y **no** su nacionalización.

El proveedor lo avisa con su importe y dice cómo se arregla: **alargando `horizonte_meses`**. Sin ese aviso, la fila de nacionalización se ve más chica que la de pagos y no hay nada en pantalla que lo explique.

### Las versiones oficiales de hoy

| versión | temporada | período | calculada | unidades | FOB U$S | `inc_fob` ponderado |
| ---: | --- | --- | --- | ---: | ---: | ---: |
| 11 | INV 27 | 01/02/27–31/07/27 | 2026-09-23 | 336.916 | 2.568.164,68 | 48,09 % |
| 9 | VER 27-28 | 01/08/27–31/01/28 | 2026-09-22 | 643.426 | 4.658.166,34 | 41,39 % |

La ventana toma el **40,45 %** de `INV 27` y el **64,02 %** de `VER 27-28`: el resto queda antes o después del tramo que se proyecta.

---

## 10. Los insumos pesados van a un job

### Por qué

Medido contra la base el **24/09/2026**, antes del cambio:

| Pedido | Tiempo |
| --- | ---: |
| Pestaña *Proyección* (`getGrilla`) | 33,9 s / 39,9 s |
| Fila del tablero (`COMPRAS_PROY`) | 31,0 s / 33,3 s |
| Tablero entero | 39,1 s |

Y adentro de eso, por paso (tres corridas en frío):

| Paso | ms |
| --- | ---: |
| **Historia de recepciones** (CPA35 + STA20) | **38.312 / 45.410 / 47.660** |
| Contraste de la vista | 59 / 186 / 184 |
| Versiones oficiales | 36 / 162 / 90 |
| Lo ya comprado | 47 / 47 / 47 |
| Ajustes, curva, parámetros | menos de 500 cada uno |
| La cuenta en PHP | **0,5** |

Una sola consulta era el 95 % del tiempo. STA20 tiene 11,9 millones de filas, y la consulta entraba por el índice de `TCOMP_IN_S` —todos los remitos de proveedor de todos los proveedores— y evaluaba dos veces el mismo CTE.

### Qué se materializa y qué no

| Insumo | Dónde queda | Por qué |
| --- | --- | --- |
| Historia de recepciones | `RO_T_CASHFLOW_COMEX_RECEP_HIST`, por `RO_SP_CASHFLOW_COMEX_RECEP_HIST` | Es historia de años cerrados. Se guardan **diez años**: cambiar `compras_proy_anios_cuota` no obliga a correr nada, PHP filtra los que usa |
| Presupuesto oficial por temporada | `RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN`, por `RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN` | Cambia cuando alguien marca una versión, no en cada pedido |
| Contraste de la vista | `RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE`, mismo SP | Sale de las mismas tablas y en el mismo momento |
| Detalle por rubro de una versión | **en vivo** | Sólo se pide al abrir el modal |
| Lo ya comprado, los ajustes, la curva | **en vivo** | Un contenedor cargado tiene que descontar en el momento. Y juntos tardan menos de medio segundo |

**La cuenta no se movió.** Ventana, cuota, descuento, ajustes y valuación siguen en `ComprasProyectadas`, con sus pruebas. Duplicarla en SQL dejaría dos definiciones del mismo número.

El presupuesto se trae **desde central** por el linked server `[XL-APPS]`, el mismo que usa la curva de dólar futuro, y no con un SP en `POWER_BI_CONTROL`. Así el cashflow lee **una** base, y la pestaña deja de abrir cinco conexiones a `POWER_BI_CONTROL` en cada pedido.

### El SP de la historia, y por qué tarda un segundo

Va al revés que la consulta en vivo. Primero las **órdenes** del exterior (unas 1.600, de CPA35); después sus movimientos por `IX_6` (`N_ORDEN_CO`), **una sola vez**, a una temporal. Diez años en ~1 s.

**El filtro por fecha va al final, a propósito.** El denominador del prorrateo es el total recibido de la orden, y tiene que sumar **todas** sus recepciones: una orden recibida en diciembre y en enero reparte su importe entre los dos años. Filtrar STA20 por fecha antes cambiaría el prorrateo. Y lo que achica la lectura no es la fecha —diez años son casi toda la tabla— sino la orden.

Verificado contra la base, corriendo la lógica del SP sin escribir nada: para 2023–2025 da **los mismos 36 meses** que la consulta en vivo, con **0 unidades** de diferencia y **U$S 0,000035** de diferencia máxima por redondeo. En 1,4 s contra 31,2 s.

### Cómo reemplazan las tablas

Los dos SP calculan todo en temporales —el presupuesto trae lo remoto **antes** de abrir la transacción, así que no necesita MSDTC— y recién al final hacen `DELETE` + `INSERT` en **una** transacción. Quien lee durante la corrida ve la versión anterior entera, nunca una tabla vacía.

- **Una historia vacía no se graba**: es señal de que algo se rompió, no de que se dejó de importar. Se registra el error y queda la anterior.
- **Un presupuesto sin versiones oficiales sí se graba**: es un estado válido de la app de compras, y la pestaña lo muestra mes por mes como `SIN_PRESUPUESTO`.
- **Sin la vista no se pisa nada**: la corrida falla con el error en el log y queda el presupuesto anterior, que la pantalla avisa como viejo.
- **Dos corridas a la vez no se pisan**: la segunda encuentra el lock de aplicación tomado, lo deja en el log y sale.

### El log

`RO_T_CASHFLOW_JOB_LOG`: una fila por corrida, con `PROCESO`, `INICIO`, `FIN`, `FILAS`, `ERROR` y `USUARIO`. La fila se inserta al **empezar**, fuera de la transacción, así que una corrida que se cae queda registrada igual. El `CATCH` completa `FIN` y `ERROR` y relanza el error, para que el paso del job quede fallido también en el SQL Agent.

### Los jobs

**No los crea ningún script.** La programación sugerida está al pie de cada SP:

| Job | Frecuencia |
| --- | --- |
| Historia de recepciones | Diaria, 05:00 |
| Presupuesto de compras | Diaria, 05:00, y cada 30 minutos de 08 a 20 en días hábiles |

Lo ideal para el presupuesto es que la app de compras arranque el job (`sp_start_job`) al marcar una versión oficial. Eso vive en el repo de compras.

> **Ojo con el login del linked server.** Corriendo desde el Agent, la consulta a `[XL-APPS]` sale con el mapeo de login de la cuenta del servicio. Si no existe, el job falla con *Login failed*: queda en el log y la pestaña lo muestra.

---

## Lo que este módulo no hace

- **No escribe en tablas de compras, de Tango ni de Comercio Exterior.** Las tres se leen y se dejan como están. Hay una prueba que busca `INSERT`, `UPDATE`, `DELETE`, `MERGE`, `DROP` y `TRUNCATE` sobre `ComprasProyectadas.php` y `ComprasProyectadasDatos.php` y exige cero en las seis. Lo único que escribe es la tabla de ajustes, que es del cashflow, y vive en una clase aparte **para que esa prueba siga siendo posible**.
- **No toca las filas existentes del tablero.** *Proveedores Exterior* y *Nacionalizaciones* siguen trayendo exactamente lo que traían: los contenedores cargados, ubicados por sus fechas del maestro. El script sólo les declara su `NATURALEZA`.
- **No proyecta flete ni seguro.** Son costos logísticos y quedaron fuera de alcance. Es el mismo pendiente que anota `README-comex.md`: al 21/09/2026 son U$S 208.560,00 y U$S 5.353,05 sobre 60 contenedores.
- **Sólo Argentina.** Uruguay tiene su propia base (`POWER_BI_CONTROL_URUGUAY`) y su propio presupuesto.

---

## Pruebas

```bash
php tests/run.php compras_proyectadas
```

| Archivo | Qué fija |
| --- | --- |
| `tests/test_compras_proyectadas.php` | Las tres reglas puras: temporada, cuota y ventana. **98 comprobaciones, sin base** |
| `tests/test_compras_proyectadas_estimacion.php` | La cuenta entera y los siete estados. 120, de las cuales 47 contra la base |
| `tests/test_compras_proyectadas_provider.php` | El proveedor, el registro, el script y el cableado de la pantalla. 112 |
| `tests/test_compras_proyectadas_ajustes.php` | El ajuste manual y su descarte. 64 |
| | **394 en total** |

Lo que necesita SQL Server se saltea solo, y **es todo de lectura**: ninguna prueba da de alta ni de baja un ajuste contra la base real.

### Lo que se prueba sin base, y por qué tiene que ser así

En la base de hoy **no se puede producir** ninguno de los casos que más importan: el descuento da cero, no hay ningún ajuste cargado, las dos temporadas de la ventana tienen versión oficial y no se puede cambiar una versión oficial a voluntad para ver qué pasa. Todo eso se fija con datos escritos a mano:

- Cada temporada descontando contra la fecha de **su** versión, con dos cortes distintos en la misma ventana.
- El exceso que no se compensa y la estimación que nunca es negativa.
- Dos meses de recepción compartiendo mes de pago, consumiendo el cargado **una** vez.
- Un mes sin oficial en `SIN_PRESUPUESTO`, y el aviso nombrando la temporada.
- El ajuste que reemplaza, y el que se descarta al cambiar la versión — dejando intacto el de la otra temporada.

### Y pruebas de cableado, que leen los archivos

Con el mismo criterio que las de Comex: **sin sus comentarios**. Estos archivos explican en prosa lo que *no* hacen, y buscar el patrón sobre el texto entero daría positivo en la nota que dice que el patrón no está.

> En el SQL se sacan **sólo los comentarios de bloque**, no los de línea. Un `--` también puede estar adentro de un literal, y este script tiene un `PRINT '--- Estado ---'`: borrar desde ahí hasta el fin de línea se come la comilla de cierre y el punto y coma, y a partir de ese punto cualquier patrón da positivo contra el resto del archivo.

Dos cosas que las pruebas fijan **porque ya fallaron de verdad** en la primera corrida del script:

- Un `PRINT` no puede armar su texto con una subconsulta: es error de **sintaxis**, así que el lote entero no corre y ningún parámetro se crea.
- Los parámetros llevan `TIPO_DATO`, que es `NOT NULL` en la tabla.

---

## Archivos

```
sql/cashflow_compras_proyectadas.sql          Las dos filas, los grupos, la tabla y los parametros
sql/cashflow_comex_materializado.sql          El log de corridas y las tres tablas materializadas
sql/RO_SP_CASHFLOW_COMEX_RECEP_HIST.sql       El SP de la historia de recepciones
sql/RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN.sql   El SP del presupuesto oficial y el contraste
cashflow/Class/ComprasProyectadas.php         Las reglas PURAS: temporada, cuota, ventana y la estimacion
cashflow/Class/ComprasProyectadasDatos.php    Las tres lecturas. NO escribe nada
cashflow/Class/ComprasProyectadasAjustes.php  Lo unico que escribe: el ajuste manual
cashflow/Class/Providers/ComprasProyectadasProvider.php   Las dos series del tablero
cashflow/Class/CashflowRegistry.php           El modulo y sus dos series
cashflow/Controller/ComprasProyectadasController.php      La grilla, el detalle y el ABM del ajuste
cashflow/Tabs/compras_proyectadas.php
cashflow/Tabs/parametros_compras_proy.php
cashflow/Js/Compras-Proyectadas.js
cashflow/Js/Parametros-Compras_proy.js
cashflow/Css/Compras-Proyectadas.css
tests/test_compras_proyectadas.php
tests/test_compras_proyectadas_estimacion.php
tests/test_compras_proyectadas_provider.php
tests/test_compras_proyectadas_ajustes.php
```

Lo que se lee y **nunca** se escribe, de tres duenios distintos:

```
POWER_BI_CONTROL   RO_V_COMPRA_PROYECTADA_VIGENTE     el presupuesto oficial
                   RO_T_HISTORIAL_COMPRAS_PROYECTADAS_*  solo para el contraste
central (Tango)    CPA35 + STA20                      la historia de recepciones
central (Comex)    RO_T_IMPORTACIONES_ENCABEZADO      lo ya comprado
                   RO_T_IMPORTACIONES_ENCABEZADO_PAGOS   y lo ya pagado de eso
                   RO_T_IMPORTACIONES_DETALLE         para saber cual ya cerro
```

---

## Pendientes conocidos

- **El filtro de cuero vive sólo en la base.** Hay que bajarlo al `05_baja_logica_versiones.sql` del repo compras o la próxima corrida lo borra. Ver la sección 7.
- **La nacionalización de los últimos meses de la ventana cae fuera del eje.** Es estructural, está avisado con su importe, y se corrige subiendo `horizonte_meses`. Ver la sección 9.
- **Sin login: el ajuste se graba con `USUARIO = NULL`.** La costura está puesta —`guardar()` lo recibe y la tabla lo guarda— para que el día que exista no haya que tocar nada. Es el mismo pendiente que el resto del módulo.
- **El ajuste manual no tiene una pantalla propia de historial global.** Se ve por mes, desde el modal. `getHistorialAjustes` sin `mes` ya devuelve todo; falta la pantalla. Mismo estado que el historial de fechas de Comex.
- **La ventana no puede empezar antes del mes en curso.** Un mes de recepción ya pasado cuyo pago todavía no se hizo no se proyecta. No apareció como necesidad, pero es la primera cosa que va a faltar si alguien quiere ver un pago atrasado.
- **Flete y seguro no los proyecta nadie**, ni acá ni en las dos pestañas de Comex. Ver *Pendientes conocidos* de `README-comex.md`.
- **Nadie validó la cuota contra el criterio de compras.** El reparto por mes sale de la historia de recepciones de Tango, que es un hecho; pero si el área de compras tiene su propio calendario de oleadas, ése sería un mejor insumo y hoy no se lee.
