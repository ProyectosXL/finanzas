# Módulo Cobranzas FR — Real a Cobrar vs Pendientes Proyectados (PPP)

Reemplaza la matriz unificada de **Cobranzas FR** dividiendo la gestión en dos matrices por solapas independientes (*Real a Cobrar* y *Pendientes Proyectados*) y alimenta las series del tablero de Cashflow: `COBRANZA`, `COBRANZA_PROYECTADA` y `COBRANZA_TOTAL`.

Rama: `feature/cobranzas-fr-proyeccion`

---

## La idea en una línea

**Las facturas pendientes se proyectan sumando a su fecha de emisión el Plazo Promedio de Pago (PPP) del grupo empresario del cliente —salvo que alguien haya cargado la fecha de esa factura a mano, que entonces manda—, y el descuento sale de una escala general por tramo de días.**

```
Facturas Pendientes (GVA12 FAC en estado PEN)
        │
        ├─ Excluye comprobantes ya contados por Real (ACEPTADA) o ya cobrados (PAGADO)
        ├─ PPP del GRUPO EMPRESARIO = promedio de los PPP por cliente del grupo,
        │      con los recibos de Tango de los últimos 100 días (RO_V_CASHFLOW_PPP_GRUPO)
        ├─ Si el grupo tiene PPP manual en Parámetros, pisa el calculado
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
-- 5. sql/cashflow_cobranzas_ppp_grupo.sql
```

El primero crea `RO_T_CASHFLOW_COBRANZAS_PARAM_DESC` (escalas por cliente, que ya no se leen desde el script 3) y `RO_T_CASHFLOW_COBRANZAS_CLIENTE_CONFIG` (que nunca se leyó). **No** agrega `PPP_MANUAL` a `RO_T_PARAMETROS_DESC_CLIENTES`: esa columna existe en la base pero ningún script de este repo la crea, y desde el script 5 tampoco se lee.

El segundo parte la fila `COBRANZAS_FR` del tablero en sus dos componentes. Es reejecutable y no borra nada.

El tercero crea `RO_T_CASHFLOW_COBRANZAS_ESCALA_DESC` y siembra la escala de descuento general. El cuarto crea `RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL`, donde viven las fechas de cobro cargadas a mano. Los dos son reejecutables. Sin el tercero, todas las facturas proyectan con **0% de descuento**; sin el cuarto, la fecha de cobro siempre sale del PPP y la celda editable no guarda nada — ninguno de los dos rompe la pantalla.

El quinto crea la vista `RO_V_CASHFLOW_PPP_GRUPO` (sobre `dbo.GC_VIEW_PPP`, que tiene que existir) y la tabla `RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO` del PPP manual por grupo, y migra a ella los PPP manuales por cliente que había. Sin él, el PPP calculado queda vacío, la tarjeta de Parámetros avisa qué script falta y la proyección cae al respaldo (`DIAS_PP_MAX` del cliente, o 30).

---

## Las Dos Matrices (Solapas Principales)

La interfaz de `Cobranzas FR` cuenta con dos pestañas de navegación dedicadas en la cabecera superior:

### 1. Solapa "Real a Cobrar"
- **Origen de datos:** Propuestas de pago de franquicias (`FP_propuestas_pago` en la base `apps`).
- **Criterio:** Facturas comprometidas por fecha efectiva de cobro pactada (`fecha_propuesta_pago`), **únicamente de propuestas en estado `ACEPTADA`**.
- **Estilo:** Visualización estándar en verde/azul (`.badge-cobro`).
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

**El PPP es por grupo empresario.** Los franquiciados con varios locales pagan como grupo, así que el plazo se calcula por `GVA14.GRUPO_EMPR` (nombre en `GVA62.NOMBRE_GRU`) y todos los clientes del grupo lo comparten. Un cliente sin grupo es su propio grupo: `COD_AGRUP = GRUPO_EMPR`, o `COD_CLIENT` si está vacío. La regla vive en `Ingresos::codAgrupador()` y espeja el `CASE` de la vista, para que el PHP busque con la misma clave que la vista devuelve.

1. **PPP Calculado** — vista `RO_V_CASHFLOW_PPP_GRUPO` (script 5), sobre `dbo.GC_VIEW_PPP` de Tango:
   - `GC_VIEW_PPP` tiene un PPP **por recibo**: los días ponderados por importe entre la emisión de las facturas imputadas y el cobro. Es factura → cobro, que es lo que la proyección suma a `FECHA_EMIS`.
   - Ventana: recibos de los últimos **100 días**, clientes `FR%`.
   - Se promedia por cliente y después por grupo (**promedio de promedios**): un cliente con muchos recibos no pesa más que uno con pocos.
   - Un grupo sin recibos en la ventana no tiene fila: su calculado es 0 y entra el respaldo.

   > Antes salía de `FP_propuestas_pago` (base `apps`): el promedio de `DATEDIFF(fecha_creacion, fecha_propuesta_pago)` de las últimas 3 propuestas pagadas. Eso mide cuánto tarda una *propuesta* en pagarse desde que se crea, no cuánto tarda el cliente en pagar la factura, y se sumaba igual a la fecha de emisión. Era el número equivocado aplicado a la fecha correcta. Por eso "el PPP no era correcto".

2. **PPP Manual** — tabla `RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO`, una fila por `COD_AGRUP`:
   - En **Parámetros → Cobranzas** cada grupo tiene un campo editable; el valor aplica a todos sus clientes. Vacío o 0 vuelve al calculado (la fila queda, con quién y cuándo lo dejó así).
   - `RO_T_PARAMETROS_DESC_CLIENTES.PPP_MANUAL` (por cliente) **ya no se lee**. La semilla del script 5 migró los que había a su agrupador, con `MAX` cuando dos clientes del mismo grupo tenían valores distintos.

3. **PPP Efectivo** — una sola regla, `Ingresos::pppEfectivo()` (antes estaba escrita tres veces: en Ingresos, en Parametros y en el JS):

   ```
   manual del grupo > 0        → ese
   calculado del grupo > 0     → ese
   DIAS_PP_MAX del cliente > 0 → ese
   si no                       → 30
   ```

   Sólo en el último escalón dos clientes del mismo grupo pueden proyectar con plazos distintos; la tarjeta lo marca.

`Ingresos::getPPPClientes()` sigue devolviendo un mapa **por cliente** con `ppp_efectivo`, así que la proyección no cambió: cada factura busca a su cliente y encuentra el plazo de su grupo (más `cod_agrup`, `nombre_agrup`, `cant_recibos` y `cant_clientes_ppp` para poder auditarlo). Las tres lecturas van a `central` y **el cruce se hace en PHP**, nunca con un join SQL entre tablas propias y de Tango: pueden tener collation distinta.

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

**El PPP sigue definiendo `Fecha probable de cobro = Fecha emisión + PPP`**, pero ahora es el del grupo empresario (ver la sección anterior). Lo que se generalizó acá es sólo el descuento.

---

## Qué franquicias lista la tarjeta de Parámetros

La tarjeta **Gestión de Cobranza Franquicias** muestra una fila por grupo empresario y, debajo, sus clientes. Los clientes son **sólo las franquicias habilitadas en el directorio de sucursales**: `SUCURSALES_LAKERS` (servidor `locales`, con el prefijo de `Conexion::prefijoLocales()` igual que en Saldos) con `CANAL = 'FRANQUICIAS' AND HABILITADO = 1 AND NRO_SUC_MADRE IS NULL` — hoy 84, contra 204 clientes `FR%` en `GVA14`. Cada cliente muestra su número y nombre de sucursal (un cliente con dos sucursales las ve concatenadas), y el pie de la tarjeta dice cuántas franquicias de Tango quedaron afuera.

**Informar de más antes que vacío:** si el servidor de locales no responde, o el directorio no devuelve ninguna franquicia, la tarjeta muestra **todas** las de Tango con un aviso. Una tarjeta en blanco dejaría sin editar el PPP de todo el mundo por una caída ajena.

**La proyección no se filtra.** Una franquicia dada de baja con una factura abierta sigue proyectando en Cobranzas FR: el filtro es de la tarjeta de Parámetros, no del universo a cobrar. El cruce (`Parametros::mapaSucursales()`, `filtrarFranquiciasActivas()`) y el agrupado (`agruparPorAgrupador()`) son helpers puros con prueba.

---

## Fecha de cobro manual por factura

El PPP es un promedio: sirve para el grueso de la cartera y no sirve cuando alguien ya habló con el franquiciado y sabe la fecha de esa factura. Esa fecha se carga en el **Detalle Facturas de Pendientes Proyectados**, celda por celda, y vive en `RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL`.

### La jerarquía

```
Fecha de cobro = fecha manual  (si hay una cargada para ese comprobante)
               = Fecha emisión + PPP del grupo del cliente  (si no)

Días = DATEDIFF(day, Fecha emisión, Fecha de cobro)   ← SIEMPRE sobre la fecha resuelta
```

**No es sólo mover el importe de columna.** Los días salen de la fecha resuelta y no del PPP, así que la fecha manual cambia el tramo de la escala y con él el porcentaje y el importe neto. Devolver el PPP como "días" cuando hay fecha manual dejaría el descuento calculado sobre un plazo que no existe. La regla está escrita una sola vez en `Ingresos::resolverFechaCobro()`, que es pura y está probada.

La diferencia se calcula **con signo**: una fecha manual anterior a la emisión da días negativos, que no caen en ningún tramo y por lo tanto no descuentan. `DateTime::diff()->days` siempre es positivo y hubiera hecho que ese caso cayera en un tramo con descuento.

### Cuatro decisiones

- **No se aceptan fechas pasadas.** El `input type="date"` lleva `min` en el día de hoy, y el endpoint **valida de nuevo en el servidor**: lo que manda el navegador es un pedido, no una autorización. El motivo no es formal: la pestaña sólo muestra cobros de hoy en adelante, así que una fecha de ayer haría desaparecer la factura de la grilla y el usuario leería su edición como si hubiera borrado la fila.
- **La fecha manual sobrevive a la factura.** No se limpia cuando el comprobante sale del listado —se cancela, se paga o entra en una propuesta—. Queda guardada y vuelve a aplicar sola si reaparece. Borrarla automáticamente perdería una decisión que alguien tomó, y el síntoma sería una fecha que "se desconfigura sola".
- **La celda editada distingue lo pactado de lo estimado.** Sin esa marca, dos filas con la misma fecha en pantalla estarían diciendo cosas distintas y no habría forma de saber cuál es cuál. El botón de volver borra el override y la fecha vuelve al PPP.
- **Al guardar se recarga la pestaña entera**, no la fila. La fecha cambia los días, el descuento, el neto, en qué columna del eje cae ese importe y los totales del pie: parchearlo en el navegador sería reimplementar en JS la cuenta que ya hace el backend, con el riesgo habitual de que las dos den distinto.

En **Resumen** la fila es un cliente y no un comprobante, así que no hay nada que editar: se muestra un indicador cuando alguna de sus facturas tiene fecha cargada a mano, y el detalle está en Detalle Facturas.

Los endpoints son `IngresosController?action=saveFechaCobroManual` y `deleteFechaCobroManual`.

### Y se ve en el tablero

Una factura con fecha manual **no está en ninguna propuesta** —si lo estuviera sería cobranza real— pero su fecha **tampoco es una estimación**: Tesorería la habló con el cliente y la acordó por fuera de la app de cobranzas. Es una negociación, y quien mira el tablero para decidir necesita poder distinguirla de un promedio estadístico. En la grilla los dos números tienen exactamente la misma pinta.

Por eso la fila **Cobranzas Franquicias Proyectadas** del tablero anota sus celdas:

- La celda que tiene parte pactada lleva un **subrayado violeta**, y el tooltip dice cuánto y de cuántos comprobantes: *"$ 386.814,96 de esta celda (1 comprobante) tienen fecha de cobro PACTADA con el cliente… El resto de la celda sale del PPP, que es una estimación."*
- El nombre de la fila lleva un **🤝** con el total pactado **de la vista activa**, para poder verlo sin recorrer veintiocho columnas con el mouse.
- El detalle factura por factura sigue estando en *Pendientes Proyectados → Detalle Facturas*.

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
- **Filtro por fecha de emisión (desde – hasta):** ver más abajo.
- **Modo Resumen vs Detalle Facturas:** ver más abajo.
- **Selector de Eje Temporal:** Alterna entre *Días* (tramo diario), *Meses* (tramo mensual) y *Período Completo* mediante el componente común `Js/eje-vistas.js`.
- **Botones de Acción:** *Actualizar* datos y *Exportar* matriz a Excel.

---

## Detalle Facturas abre ordenado por fecha de cobro

Lo más antiguo arriba, que es lo primero que hay que mirar de una cartera
pendiente. Antes abría en el orden en que la consulta devolvía las filas.

Son tres piezas y ninguna sirve sola:

| Pieza | Dónde | Qué aporta |
| --- | --- | --- |
| `porDefecto: { columna: 'cobro', dir: 'asc' }` | `Js/Ingresos-Cobranzas_fr.js` | el orden con el que abre |
| `data-orden-nombre="cobro"` | el `<th id="thCobroCob">` de la pestaña | un nombre estable para una columna que cambia de rótulo |
| `display: none` en `.modo-resumen` | `Css/Ingresos-Cobranzas_fr.css` | que el default **no** se aplique en *Resumen* |

**No se guarda en `localStorage`.** Un default escrito ahí sería
indistinguible de una elección del usuario, y le pisaría el orden que eligió a
mano. Lo aplica `crear()` sólo cuando no hay nada guardado, y cualquier click
en un encabezado lo reemplaza para siempre.

**Vale en las dos solapas.** Es la misma columna física: dice *F. Prob. Cobro*
en Pendientes Proyectados y *Cobro* en Real a Cobrar, y en las dos es la fecha
en que entra la plata. El `data-orden-nombre` es lo que hace que sean una sola
columna a los efectos del orden — y de paso arregla que **el orden elegido a
mano se perdía al cambiar de solapa**, porque la preferencia guardada nombraba
un rótulo que dejaba de existir.

**No vale en *Resumen*, y no porque nadie lo diga.** Ahí la fila es un cliente
con facturas que se cobran en fechas distintas, así que la columna de cobro
está oculta por CSS — y `tabla-orden.js` no ordena por una columna que no se
ve, la misma guarda que ya protegía a las preferencias guardadas. Por eso hay
**una sola clave de preferencia** para los dos modos y el control no necesita
saber qué es un modo.

> Quien ya tuviera ordenada esta tabla por *Cobro* o por *F. Prob. Cobro*
> pierde esa preferencia una vez: la columna pasó a llamarse `cobro` y el
> nombre viejo se descarta. La tabla abre con el default y la primera elección
> nueva se guarda. Es lo mismo que pasa cuando una columna se va.

---

## El filtro por fecha de emisión es del servidor, no del navegador

Dos `<input type="date">` al lado del buscador, más un botón de limpiar. Filtran por
`FECHA`, que es la **fecha de emisión** del comprobante, no la de cobro.

Los dos extremos se mandan como parámetros `desde` y `hasta` a
`IngresosController`, y los items se filtran **antes** de `EjeVista::armar()` /
`armarAgrupado()`.

**Es lo único que puede funcionar.** El buscador esconde filas en el DOM, y eso alcanza
para buscar un cliente: sigue siendo la misma tabla. Un filtro hecho igual dejaría las
columnas del eje, el pie de totales y las tres tarjetas de indicadores describiendo el
total **sin** filtrar, y se vería una tabla de tres filas con un total de doscientos
millones sin nada que explicara la diferencia.

- **Se valida en el servidor** (`Ingresos::validarRangoFechaEmision()`, pura y probada):
  formato, calendario y `desde <= hasta`. El `max` y el `min` que se ponen en los inputs
  son una comodidad del navegador, no una garantía — el endpoint es alcanzable sin pasar
  por la pantalla. Mismo criterio que la fecha de cobro manual.
- **Los dos extremos son opcionales e independientes.** Vacío es *sin filtro*, no un error.
- **El filtro aplicado se ve en el rótulo del período**, en un elemento aparte del que
  escribe `eje-vistas.js`: ese se reescribe en cada cambio de vista y se llevaría puesto
  cualquier cosa agregada ahí.
- **Un comprobante sin fecha de emisión utilizable queda afuera, pero contado y avisado.**
  Es la cobranza real cuyo comprobante no aparece en `GVA12`, donde `getCobranzasFR()`
  deja `'N/A'`: no se puede ubicar en el rango, y descartarlo en silencio sería perder
  plata sin decirlo.

> **Con filtro, el Resumen paga la consulta que se ahorraba.** El modo resumen de
> `getCobranzasFR()` se saltea la fecha de emisión de la cobranza real justamente porque
> cuesta **una consulta a `GVA12` por fila** y el Resumen no la muestra. Pero con filtro
> esa fecha es lo que decide si la fila entra: sin ella, la cobranza real quedaría entera
> afuera y el número sería falso. Así que el detalle se trae **sólo cuando hay filtro**.

---

## Resumen y Detalle Facturas son sub-solapas, no botones

Antes eran un `btn-group` al lado de *Actualizar* y *Exportar*. Un `btn-group` es el
control de una **acción**, y ahí había tres cosas con la misma pinta de las cuales sólo dos
cambiaban lo que la tabla muestra.

Ahora son un `<ul class="nav nav-tabs">` anidado, **dentro del panel y debajo de las
solapas principales**, que es lo que las hace leer como dos formas de mirar la misma tabla
y no como otro origen de datos. Se muestran en los dos orígenes —*Real a Cobrar* y
*Pendientes Proyectados*—, porque es la misma tabla en los dos.

- **Lo que sigue habilitado sólo en *Pendientes Proyectados → Detalle Facturas* es la
  edición de la fecha de cobro manual.** `editable()` no cambió.
- **El estado sigue viviendo en `modoVista`** (`'resumen'` / `'deepdive'`). Lo que cambió es
  el control y su marcado; `cambiarModo()` hace lo mismo que antes con una clase distinta.
- **En Cobranzas May van directamente arriba de la tabla**: esa pestaña no tiene solapas
  principales, así que no hay nada debajo de lo que anidarlas.

### "Deep Dive" pasó a llamarse "Detalle Facturas"

Sólo el texto de cara al usuario: rótulos, títulos, tooltips y avisos. **Los
identificadores internos quedaron como estaban** — `deepdive`, `btnVistaDeepDiveCob`, el
parámetro `type=deepdive`—, porque renombrarlos no cambia nada en pantalla y sí toca el
endpoint, el controller y las pruebas.

---

## Resumen: una fila por cliente

Antes el Resumen agrupaba por **cliente + fecha de cobro**, así que un cliente con cobros en tres fechas ocupaba tres filas. Eso no es un resumen: es el detalle con menos columnas.

Ahora es **una fila por cliente**, con sus importes repartidos en las columnas de la grilla según la fecha de cada comprobante. Una misma fila puede tener plata en el 6/9, en el 8/9 y en la columna de octubre.

| | Resumen | Detalle Facturas |
| --- | --- | --- |
| La fila es | un cliente | un comprobante |
| Columnas | `COD_CLI`, `RAZON_SOC`, `Importe Bruto`, `Importe Neto` + la grilla | todas, incluidas `FECHA`, `T_COMP`, `N_COMP`, `Desc`, `Días` y `Cobro` |
| Lo arma | `EjeVista::armarAgrupado()` | `EjeVista::armar()` |

**El agrupado lo hace `EjeVista`, no la consulta.** Es la parte que importa: agrupar por cliente en SQL obligaría a elegir entre repetir la fila por cada fecha o quedarse con una sola fecha y tirar la ubicación temporal del resto de la plata. `armarAgrupado()` suma las **series** de todos los comprobantes del cliente, así que cada importe conserva su columna y la fila es una sola. Está documentado en el encabezado de `Class/EjeVista.php` y probado en `tests/test_ejevista.php`, incluido el invariante de que las dos formas dan el mismo total.

### La columna TIPO se fue

El badge REAL/PROYECCIÓN **repetía el encabezado en cada fila**: la solapa activa ya dice
si lo que se está mirando es real o proyectado. Distinguía algo sólo dentro de la vista
"todos", que no es la que se usa.

Lo que sí distinguía sigue estando, porque no era el badge: **el color de la fila**
(`.fila-proyeccion`) y **el PPP con el que se proyectó la fecha**, que pasó al `title` de
`COD_CLI`. Sin ese dato la fecha de cobro no se puede auditar — es un promedio, no un
dato del comprobante.

Sacar una columna del medio mueve más cosas de las que parece, y todas son índices:

| Qué | Antes | Ahora |
| --- | --- | --- |
| `porDefecto` de `crearColumnasFijas()` | `[0, 1, 2]` | `[0, 1]` |
| `colspan` del rótulo TOTALES del `tfoot` | `10` (FR) / `11` (May) | `9` / `10` |
| `.modo-resumen` — columnas de detalle | `nth-child(4..8)` | `nth-child(3..7)` |
| `.modo-resumen` — columna `Cobro` | `nth-child(11)` | `nth-child(10)` |

Y una que no es un índice: **`TIPO_REGISTRO` salió del buscador**. `filtrarTabla()` esconde
filas mirando el `textContent` de la fila, y desde que no hay columna Tipo el texto "REAL"
o "PROYECCIÓN" no está en el DOM. Si siguiera en `filasFiltradas()`, buscar *real* dejaría
los totales de esas filas y escondería las filas: el pie no cerraría con la tabla. Las dos
funciones tienen que mirar lo mismo.

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
- El filtro por fecha de emisión: extremos vacíos, extremos sueltos, los bordes del rango inclusive, el rango al revés rechazado, el calendario imposible, y que el comprobante sin emisión quede afuera **contado** y avisado.
- El PPP por grupo: la regla del efectivo (`pppEfectivo()`: manual → calculado → `DIAS_PP_MAX` → 30, con vacíos y strings de la base), el agrupador (`codAgrupador()`), que los clientes de un grupo compartan el PPP del grupo y que un grupo sin recibos caiga al manual, al respaldo del cliente o a 30 (`armarPPPPorCliente()`).
- La tarjeta de Parámetros: el cruce con el directorio de sucursales (`mapaSucursales()` concatena varias sucursales del mismo cliente; `filtrarFranquiciasActivas()` descarta sin avisar, y sin directorio muestra todos **con** aviso) y el agrupado en una fila por agrupador con sus clientes ordenados (`agruparPorAgrupador()`).

`tests/test_cobranzas_fr_split.php`:
- Que lo que cuenta *Real* y lo que la proyección excluye sean complementarios, estado por estado.
- Que la estructura partida valide contra el registro real, y que reactivar la fila total no.

```bash
php cashflow/tests/run.php
```

