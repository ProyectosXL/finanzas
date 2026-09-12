# Módulo Cobranzas FR — Real a Cobrar vs Pendientes Proyectados (PPP)

Reemplaza la matriz unificada de **Cobranzas FR** dividiendo la gestión en dos matrices por solapas independientes (*Real a Cobrar* y *Pendientes Proyectados*) y alimenta las series del tablero de Cashflow: `COBRANZA`, `COBRANZA_PROYECTADA` y `COBRANZA_TOTAL`.

Rama: `feature/cobranzas-fr-proyeccion`

---

## La idea en una línea

**Las facturas pendientes se proyectan sumando a su fecha de emisión el Plazo Promedio de Pago (PPP) de cada cliente —salvo que alguien haya cargado la fecha de esa factura a mano, que entonces manda—, y el descuento sale de una escala general por tramo de días.**

```
Facturas Pendientes (GVA12 FAC en estado PEN)
        │
        ├─ Excluye comprobantes ya contados por Real (ACEPTADA) o ya cobrados (PAGADO)
        ├─ PPP del Cliente = Promedio de días de plazo de los últimos 3 cobros
        ├─ Si existe PPP_MANUAL en Parámetros, pisa el PPP calculado
        ▼
Fecha Probable de Cobro = Fecha Emisión + PPP
        │
        ├─ …salvo que haya FECHA MANUAL para ese comprobante, que manda
        ├─ Días = DATEDIFF(emisión, fecha de cobro resuelta)
        ├─ Escala de descuento GENERAL por tramo de días (una sola, sin medio de pago)
        ▼
Importe Neto Proyectado = Importe Bruto * (1 - % Descuento)
        │
        ▼
   Solapa "Pendientes Proyectados" (destacada en amarillo)
        │
        ▼
   serie COBRANZA_PROYECTADA → Cashflow
```

---

## Ejecución de los scripts

Contra `central`:

```sql
-- 1. sql/cashflow_cobranzas_parametros.sql
-- 2. sql/cashflow_estructura_split_cobranzas_fr.sql
-- 3. sql/cashflow_cobranzas_escala_general.sql
-- 4. sql/cashflow_cobranzas_fecha_manual.sql
```

1. Crea la tabla `RO_T_CASHFLOW_COBRANZAS_PARAM_DESC` para administrar las escalas de descuento por cliente, tramo de días (`DIAS_DESDE`, `DIAS_HASTA`) y medio de pago (`ECHEQ`).
2. Agrega la columna `PPP_MANUAL INT NULL` a la tabla `RO_T_PARAMETROS_DESC_CLIENTES`.
3. Es reejecutable y cuenta con índices por `COD_CLIENT`, `MEDIO_PAGO` y `ACTIVO`.

El segundo parte la fila `COBRANZAS_FR` del tablero en sus dos componentes. Es reejecutable y no borra nada.

El tercero crea `RO_T_CASHFLOW_COBRANZAS_ESCALA_DESC` y siembra la escala de descuento general. El cuarto crea `RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL`, donde viven las fechas de cobro cargadas a mano. Los dos son reejecutables. Sin el tercero, todas las facturas proyectan con **0% de descuento**; sin el cuarto, la fecha de cobro siempre sale del PPP y la celda editable no guarda nada — ninguno de los dos rompe la pantalla.

---

## Las Dos Matrices (Solapas Principales)

La interfaz de `Cobranzas FR` cuenta con dos pestañas de navegación dedicadas en la cabecera superior:

### 1. Solapa "Real a Cobrar"
- **Origen de datos:** Propuestas de pago de franquicias (`FP_propuestas_pago` en la base `apps`).
- **Criterio:** Facturas comprometidas por fecha efectiva de cobro pactada (`fecha_propuesta_pago`), **únicamente de propuestas en estado `ACEPTADA`**.
- **Estilo:** Visualización estándar en verde/azul (`.badge-real`, `.badge-cobro`).
- **Serie del Tablero:** `COBRANZA_REAL`.

### 2. Solapa "Pendientes Proyectados"
- **Origen de datos:** Comprobantes tipo `FAC` en estado `PEN` desde `GVA12` en `central` (Tango Gestión).
- **Filtro de exclusión:** Descarta los comprobantes que ya cuenta *Real* (propuestas `ACEPTADA`) y los ya cobrados (`PAGADO`). Ver la invariante más abajo.
- **Cálculo de Fecha Probable de Cobro:**
  $$\text{Fecha Probable de Cobro} = \text{Fecha Emisión} + \text{PPP}$$
- **Estilo:** Visualización destacada en tono ámbar/amarillo (`.fila-proyeccion`, `.badge-proyeccion`, `.cell-with-proy`).
- **Serie del Tablero:** `COBRANZA_PROYECTADA`.

---

## La invariante entre las dos solapas

Los estados que existen de verdad en `FP_propuestas_pago` son exactamente tres: `ACEPTADA`, `PAGADO` y `PENDIENTE_APROBACION_CLIENTE`.

| Estado | Real a Cobrar | Pendientes Proyectados |
| --- | --- | --- |
| `ACEPTADA` | ✅ la cuenta | ❌ excluida |
| `PAGADO` | ❌ | ❌ — ya se cobró, no es plata a cobrar |
| `PENDIENTE_APROBACION_CLIENTE` | ❌ no está comprometida | ✅ vuelve a la proyección |

**Las dos consultas se tienen que mover juntas.** Antes, *Real* traía todo lo que no estuviera rechazado, cancelado, pagado ni vencido — o sea también lo que esperaba la aprobación del cliente— y la proyección excluía exactamente ese mismo conjunto. Acotar *Real* a `ACEPTADA` sin tocar la exclusión hubiera dejado las propuestas pendientes de aprobación **fuera de las dos solapas**: la plata se evapora en silencio y no hay ninguna pantalla donde se note.

Por eso la exclusión de la proyección es el **complemento exacto** de lo que cuenta *Real*, más lo ya cobrado. Vive escrita en dos constantes de `Class/Ingresos.php` (`ESTADOS_REAL` y `ESTADOS_YA_CONTADOS`), comentada en las dos funciones y probada en `tests/test_cobranzas_fr_split.php`, porque son dos consultas contra dos bases distintas y nada más las mantiene alineadas.

`getPPPClientes()` sigue usando `PAGADO` y no participa de esto: es el histórico con el que se calcula el plazo, no el universo a cobrar.

---

## La fila del tablero está partida en dos

`IngresosProvider` siempre expuso `COBRANZA_REAL` y `COBRANZA_PROYECTADA` como series separadas, pero el tablero tenía **una** fila apuntada a `COBRANZA`, que es la suma de las dos. En pantalla eso es un solo número que mezcla lo comprometido con lo estimado.

Ahora son dos filas, y el cambio es **de datos, no de código** — que es para lo que existe `RO_T_CASHFLOW_CONF_FILA`:

| CODIGO | NOMBRE | SERIE |
| --- | --- | --- |
| `COBRANZAS_FR_REAL` | Cobranzas Franquicias (Prop. aceptadas) | `COBRANZA_REAL` |
| `COBRANZAS_FR_PROY` | Cobranzas Franquicias Proyectadas | `COBRANZA_PROYECTADA` |

`COBRANZAS_FR` queda con `ACTIVO = 0`: **no se borra**, hay histórico de configuración y el editor la sigue mostrando. Y no puede volver a activarse junto a sus dos hijas — el registro declara `COBRANZA = [COBRANZA_REAL, COBRANZA_PROYECTADA]` en su bloque `componentes` y el validador rechaza tener el total y sus partes a la vez, porque contaría dos veces el mismo importe.

El script es `sql/cashflow_estructura_split_cobranzas_fr.sql`, y **toma la sección de la fila que reemplaza en vez de escribirla a mano**: en una base que corrió `cashflow_estructura_disponibilidades.sql`, `COBRANZAS_FR` vive en `DISPONIBILIDADES` y la sección `INGRESOS` quedó inhabilitada, así que escribir `INGRESOS` en duro dejaría dos filas activas colgando de una sección inhabilitada.

---

## Cálculo y Override del Plazo Promedio de Pago (PPP)

El cálculo del PPP por cliente opera de la siguiente manera:

1. **PPP Calculado:**
   - Se buscan los últimos 3 cobros con estado `PAGADO` en `FP_propuestas_pago` para el cliente (`COD_CLIENT`).
   - Para cada cobro se obtiene el plazo real: `DATEDIFF(day, fecha_creacion, fecha_propuesta_pago)`.
   - Se promedian los plazos resultantes: `ROUND(AVG(dias_plazo))`.
   - Si el cliente no registra cobros históricos, se adopta el valor de respaldo por defecto (30 días).

2. **PPP Manual (Override en Parámetros):**
   - En la pestaña **Parámetros → Cobranzas**, cada cliente muestra su PPP calculado y un campo editable directo (`PPP_MANUAL`).
   - Al ingresar un valor manual, el sistema utiliza inmediatamente ese plazo para todas las proyecciones del cliente sin alterar el histórico calculado.

---

## La escala de descuento es UNA SOLA

Antes había una escala **por cliente y por medio de pago**, en `RO_T_CASHFLOW_COBRANZAS_PARAM_DESC`. En la práctica la escala comercial es una sola para todas las franquicias, así que eso obligaba a repetir la misma carga cliente por cliente y dejaba a la mayoría sin escala, cayendo a un porcentaje de respaldo distinto.

Ahora la escala vive en `RO_T_CASHFLOW_COBRANZAS_ESCALA_DESC` y **no depende del cliente ni del medio de pago**:

| Días | Descuento |
| ---: | ---: |
| 0 a 20 | 8% |
| 21 a 30 | 6% |
| 31 a 45 | 4% |
| 46 a 9999 | 0% |

```
Días = DATEDIFF(day, Fecha Emisión, Fecha de Cobro)
Importe Neto = Importe Bruto × (1 − % / 100)
```

- **`MEDIO_PAGO_DEFAULT` quedó como dato informativo del cliente.** Se sigue viendo y editando en Parámetros porque describe cómo opera, pero no interviene en el cálculo del porcentaje.
- **`RO_T_CASHFLOW_COBRANZAS_PARAM_DESC` no se borró.** Dejó de leerse y conserva sus datos. Si algún día hay que volver a escalas por cliente, el histórico está: es el mismo criterio de baja lógica que el resto del módulo.
- **El último tramo llega hasta 9999 a propósito.** Un plazo que no cae en ningún tramo devuelve 0%, y eso es indistinguible de "el tramo dice 0%". Con la escala cerrada de punta a punta, el cero es siempre una decisión cargada y no un hueco de configuración.

### Se guarda entera, y validada

El editor está en **Parámetros → Cobranzas**, arriba, al lado de la tarjeta del plazo mayorista: es un parámetro del negocio, no un atributo de un cliente.

La escala se guarda **completa** y no tramo por tramo. Es lo único que permite validar lo que importa, que son dos defectos que no se ven mirando la grilla —se ven en el importe—:

| Defecto | Qué pasa |
| --- | --- |
| **Solapamiento** | Un mismo plazo cae en dos tramos y el descuento termina dependiendo del orden en que se leyeron |
| **Hueco** | Un plazo sin tramo va con 0%, no porque alguien lo decidiera sino porque falta configuración |

La validación corre en el servidor (`Ingresos::validarEscala()`, pura y probada) y el JS la espeja **sólo para bloquear el botón y explicar por qué**. Es el mismo criterio del editor de estructura del tablero. El guardado va en una transacción: da de baja lógica los tramos vigentes e inserta los nuevos, así que no existe el estado intermedio de una escala a medio escribir.

**El PPP por cliente no cambió.** Sigue siendo el promedio de los últimos 3 cobros, sigue pisable con `PPP_MANUAL` desde la misma pantalla, y sigue siendo lo que define `Fecha probable de cobro = Fecha emisión + PPP`. Lo único que se generalizó es el descuento.

---

## Fecha de cobro manual por factura

El PPP es un promedio: sirve para el grueso de la cartera y no sirve cuando alguien ya habló con el franquiciado y sabe la fecha de esa factura. Esa fecha se carga en el **Deep Dive de Pendientes Proyectados**, celda por celda, y vive en `RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL`.

### La jerarquía

```
Fecha de cobro = fecha manual  (si hay una cargada para ese comprobante)
               = Fecha emisión + PPP del cliente  (si no)

Días = DATEDIFF(day, Fecha emisión, Fecha de cobro)   ← SIEMPRE sobre la fecha resuelta
```

**No es sólo mover el importe de columna.** Los días salen de la fecha resuelta y no del PPP, así que la fecha manual cambia el tramo de la escala y con él el porcentaje y el importe neto. Devolver el PPP como "días" cuando hay fecha manual dejaría el descuento calculado sobre un plazo que no existe. La regla está escrita una sola vez en `Ingresos::resolverFechaCobro()`, que es pura y está probada.

La diferencia se calcula **con signo**: una fecha manual anterior a la emisión da días negativos, que no caen en ningún tramo y por lo tanto no descuentan. `DateTime::diff()->days` siempre es positivo y hubiera hecho que ese caso cayera en un tramo con descuento.

### Cuatro decisiones

- **No se aceptan fechas pasadas.** El `input type="date"` lleva `min` en el día de hoy, y el endpoint **valida de nuevo en el servidor**: lo que manda el navegador es un pedido, no una autorización. El motivo no es formal: la pestaña sólo muestra cobros de hoy en adelante, así que una fecha de ayer haría desaparecer la factura de la grilla y el usuario leería su edición como si hubiera borrado la fila.
- **La fecha manual sobrevive a la factura.** No se limpia cuando el comprobante sale del listado —se cancela, se paga o entra en una propuesta—. Queda guardada y vuelve a aplicar sola si reaparece. Borrarla automáticamente perdería una decisión que alguien tomó, y el síntoma sería una fecha que "se desconfigura sola".
- **La celda editada distingue lo pactado de lo estimado.** Sin esa marca, dos filas con la misma fecha en pantalla estarían diciendo cosas distintas y no habría forma de saber cuál es cuál. El botón de volver borra el override y la fecha vuelve al PPP.
- **Al guardar se recarga la pestaña entera**, no la fila. La fecha cambia los días, el descuento, el neto, en qué columna del eje cae ese importe y los totales del pie: parchearlo en el navegador sería reimplementar en JS la cuenta que ya hace el backend, con el riesgo habitual de que las dos den distinto.

En **Resumen** la fila es un cliente y no un comprobante, así que no hay nada que editar: se muestra un indicador cuando alguna de sus facturas tiene fecha cargada a mano, y el detalle está en Deep Dive.

Los endpoints son `IngresosController?action=saveFechaCobroManual` y `deleteFechaCobroManual`.

### Y se ve en el tablero

Una factura con fecha manual **no está en ninguna propuesta** —si lo estuviera sería cobranza real— pero su fecha **tampoco es una estimación**: Tesorería la habló con el cliente y la acordó por fuera de la app de cobranzas. Es una negociación, y quien mira el tablero para decidir necesita poder distinguirla de un promedio estadístico. En la grilla los dos números tienen exactamente la misma pinta.

Por eso la fila **Cobranzas Franquicias Proyectadas** del tablero anota sus celdas:

- La celda que tiene parte pactada lleva un **subrayado violeta**, y el tooltip dice cuánto y de cuántos comprobantes: *"$ 386.814,96 de esta celda (1 comprobante) tienen fecha de cobro PACTADA con el cliente… El resto de la celda sale del PPP, que es una estimación."*
- El nombre de la fila lleva un **🤝** con el total pactado **de la vista activa**, para poder verlo sin recorrer veintiocho columnas con el mouse.
- El detalle factura por factura sigue estando en *Pendientes Proyectados → Deep Dive*.

**Nunca es el importe entero de la celda**, y por eso el tooltip dice cuánto: una celda del 14/9 puede tener $4.244.724 de los cuales $386.814 están pactados y el resto proyectado. Marcar sin decir cuánto haría leer los cuatro millones como acordados.

Se implementa con el campo `detalle` del contrato de proveedor, que es metadato sobre el importe y **no un importe más**: no entra en ninguna suma. Ver `README-cashflow.md`.

> **La cobranza real no se anota**, y no es un olvido: sale de una propuesta, así que su fecha siempre está acordada. Marcarla no distinguiría nada.

> **Acá sí hay borrado físico**, a diferencia del resto del módulo, y es a propósito: la fila no es un dato de negocio histórico sino un override puntual, y su baja lógica sería indistinguible de no tenerla. Lo que el módulo no borra son los importes y la configuración del tablero.

---

## Las facturas vencidas entran, ubicadas en hoy

Antes, la factura cuya **fecha probable de cobro ya había pasado** se descartaba con un
`continue` en `Ingresos::getCobranzasFRPendientesProyectadas()`. Esa plata desaparecía de
la pantalla y **nada lo decía**: la pestaña mostraba de menos en silencio, que es
exactamente lo que el resto del módulo evita.

Ahora vale el criterio de Exportaciones Tasky: **una factura con la fecha estimada en el
pasado es una factura vencida sin cobrar**, o sea información y no un error a esconder.

```
Fecha probable de cobro ≥ hoy          → va en su fecha
Fecha probable entre hoy − 180 y hoy   → va en el PRIMER DÍA DEL EJE, marcada VENCIDA
Fecha probable anterior a hoy − 180    → queda afuera
Fecha PACTADA A MANO, aunque venció    → va en su fecha, marcada VENCIDA, sin reubicar
```

La regla vive escrita **una sola vez** en `Ingresos::ubicarCobroVencido()`, que es estática
y pura, y la usan Cobranzas FR, Cobranzas May y —a través de
`estimarCobroExportacion()`— Exportaciones Tasky. Antes eran dos criterios opuestos en
tres funciones.

### Los 180 días son un techo, no una preferencia

Es la constante `Ingresos::DIAS_COBRO_VENCIDO`. Sin techo, una cartera con años de
facturas incobrables entraría **entera** en la columna de hoy, y el primer día del eje
mostraría una cobranza que nadie espera cobrar. Exportaciones Tasky pasa `null` —sin
techo— porque son pocas facturas de un solo cliente y todas se gestionan.

### La fecha manual no se reubica, y tampoco se descarta

Es una fecha que Tesorería pactó con el cliente. Moverla a hoy sería pisar una decisión
tomada con una regla automática, y el usuario vería **su propia carga en otra columna**. Se
marca vencida —eso es un hecho— pero se muestra donde está. Y no se descarta por antigua:
si cayó fuera del horizonte, lo informa `EjeVista` como cualquier otro importe.

### El descuento no cambia por reubicar el importe

Los días de la escala salen del **plazo pactado** (`resolverFechaCobro()`) y no de la
columna en la que se dibuja el importe. Si se recalcularan sobre hoy, el importe neto de
una factura vencida cambiaría solo con el paso de los días, sin que nadie tocara nada.

### Qué se ve en la pantalla

- La fila entera en ámbar (`.fila-vencida`, en `Css/main.css` porque la comparten las tres
  pestañas) y un badge `badge-vencida-exp` con la fecha que venció en el `title`.
- En **Resumen** la fila es un cliente, así que no hay una fecha que marcar: va un
  indicador al lado del código que dice que **alguna** de sus facturas está vencida, igual
  que el de fecha pactada a mano.
- Un aviso arriba de la tabla con la cantidad y el importe, generado por
  `Ingresos::avisosCobranzasVencidas()`. Son **dos** avisos y no uno, porque son dos cosas
  distintas: una reubicada está en la columna de hoy, y una pactada vencida está en la
  columna de su fecha.

> **La marca del Resumen dice "alguna", no "todas".** `EjeVista::armarAgrupado()` conserva
> sólo los campos que valen lo mismo en todo el grupo, y para un dato descriptivo eso es
> correcto. Pero una bandera no es un dato descriptivo: con la intersección, un cliente con
> una factura vencida y otra al día perdía la marca y se veía igual que uno sin ninguna.
> Lo repone `EjeVista::marcarAlguna()`, y **arregla también la marca de fecha pactada**,
> que tenía el mismo defecto desde antes.

### No se duplica contra Real a Cobrar

La exclusión de la proyección es **por comprobante** —las facturas de propuestas `ACEPTADA`
o `PAGADO`— y no por fecha, así que aceptar fechas pasadas no puede traer de vuelta nada
que *Real* ya cuente. La invariante de las dos solapas no se movió.

Lo que sí cambia es el tablero: `getCobranzasFRTotales()` ahora informa `IMPORTE_VENCIDO` y
`COMP_VENCIDOS`, y `IngresosProvider` deja un aviso con el total. **Va como aviso y no como
`detalle`**: el contrato admite una anotación por celda, y la celda de hoy de la cobranza
proyectada ya puede tener la de la fecha pactada a mano. Dos notas compitiendo por la misma
celda dejarían ver una sola, y cuál de las dos dependería del orden en que se escribieron.

---

## Barra de Herramientas y Navegación

Dentro de cada solapa, la barra de herramientas superior proporciona:
- **Buscador rápido:** Filtrado en tiempo real por código de cliente, razón social o número de comprobante.
- **Modo Resumen vs Deep Dive:** ver más abajo.
- **Selector de Eje Temporal:** Alterna entre *Días* (tramo diario), *Meses* (tramo mensual) y *Período Completo* mediante el componente común `Js/eje-vistas.js`.
- **Botones de Acción:** *Actualizar* datos y *Exportar* matriz a Excel.

---

## Resumen: una fila por cliente

Antes el Resumen agrupaba por **cliente + fecha de cobro**, así que un cliente con cobros en tres fechas ocupaba tres filas. Eso no es un resumen: es el deep dive con menos columnas.

Ahora es **una fila por cliente**, con sus importes repartidos en las columnas de la grilla según la fecha de cada comprobante. Una misma fila puede tener plata en el 6/9, en el 8/9 y en la columna de octubre.

| | Resumen | Deep Dive |
| --- | --- | --- |
| La fila es | un cliente | un comprobante |
| Columnas | `Tipo`, `COD_CLI`, `RAZON_SOC`, `Importe Bruto`, `Importe Neto` + la grilla | todas, incluidas `FECHA`, `T_COMP`, `N_COMP`, `Desc`, `Días` y `Cobro` |
| Lo arma | `EjeVista::armarAgrupado()` | `EjeVista::armar()` |

**El agrupado lo hace `EjeVista`, no la consulta.** Es la parte que importa: agrupar por cliente en SQL obligaría a elegir entre repetir la fila por cada fecha o quedarse con una sola fecha y tirar la ubicación temporal del resto de la plata. `armarAgrupado()` suma las **series** de todos los comprobantes del cliente, así que cada importe conserva su columna y la fila es una sola. Está documentado en el encabezado de `Class/EjeVista.php` y probado en `tests/test_ejevista.php`, incluido el invariante de que las dos formas dan el mismo total.

En Resumen se van `FECHA`, `T_COMP`, `N_COMP`, `Desc`, `Días` y `Cobro`: son distintos en cada comprobante del cliente. `armarAgrupado()` **descarta** los campos que difieren dentro del grupo en vez de mostrar el del primer comprobante — una fila que dijera "FAC 0001-123" cuando en realidad son doce facturas es peor que una celda vacía, porque nadie tendría por qué sospecharlo.

El pie de totales pasó a tener **una celda por columna descriptiva** en lugar de un `colspan` escrito en duro, que había que actualizar a mano cada vez que cambiaba la cantidad de columnas.

---

## Integración con el Tablero de Cashflow

El proveedor `IngresosProvider` registra tres series en `CashflowRegistry`:

| Código Proveedor | Serie | Descripción | Fila en Cashflow |
| --- | --- | --- | --- |
| `COBRANZAS_FR` | `COBRANZA_REAL` | Cobranza real de franquicias | `COBRANZAS_FR_REAL` — activa |
| `COBRANZAS_FR` | `COBRANZA_PROYECTADA` | Cobranza proyectada pendientes (PPP) | `COBRANZAS_FR_PROY` — activa |
| `COBRANZAS_FR` | `COBRANZA` / `COBRANZA_TOTAL` | Total franquicias (Real + Proyectada) | `COBRANZAS_FR` — **inactiva**, ver arriba |

---

## Pruebas Automatizadas

`tests/test_cobranzas_proyeccion.php`:
- La escala general tramo por tramo, incluidos los **bordes** (20 y 21, 45 y 46) y lo que pasa fuera del último tramo.
- El validador de la escala: solapamientos, huecos, tramos al revés, porcentajes imposibles, y que el orden de carga no cambie el veredicto.
- La jerarquía de fechas: fecha manual sobre PPP, los días recalculados sobre la fecha resuelta, y que eso cambie el tramo de descuento.
- La validación de fecha pasada, con `hoy` inyectado para que la prueba no caduque sola.
- La ubicación de las vencidas: los dos bordes del techo (180 entra y se ubica en hoy, 181 queda afuera), que hoy mismo no está vencido, que sin techo entra igual, que con techo cero se reproduce el comportamiento viejo, y que una fecha manual no se reubica ni se descarta ni siquiera más atrás del techo.
- Que el descuento no cambie por reubicar el importe.
- Los dos avisos de vencidas, y que sin vencidas no haya ninguno.
- Que `EjeVista::marcarAlguna()` marque al cliente con **una** factura vencida entre dos.

`tests/test_cobranzas_fr_split.php`:
- Que lo que cuenta *Real* y lo que la proyección excluye sean complementarios, estado por estado.
- Que la estructura partida valide contra el registro real, y que reactivar la fila total no.

```bash
php tests/run.php
```
