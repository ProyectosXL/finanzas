# Módulo Cobranzas FR — Real a Cobrar vs Pendientes Proyectados (PPP)

Reemplaza la matriz unificada de **Cobranzas FR** dividiendo la gestión en dos matrices por solapas independientes (*Real a Cobrar* y *Pendientes Proyectados*) y alimenta las series del tablero de Cashflow: `COBRANZA`, `COBRANZA_PROYECTADA` y `COBRANZA_TOTAL`.

Rama: `feature/cobranzas-fr-proyeccion`

---

## La idea en una línea

**Las facturas pendientes se proyectan sumando a su fecha de emisión el Plazo Promedio de Pago (PPP) del grupo empresario del cliente —salvo que alguien haya cargado la fecha de esa factura a mano, que entonces manda—, y el descuento sale de una escala general por tramo de días.**

```
Facturas Pendientes (GVA12 FAC en estado PEN, clientes [FL]%)
        │
        ├─ Excluye comprobantes ya contados por Real (ACEPTADA) o ya cobrados (PAGADO)
        ├─ Sólo franquicias HABILITADAS en el directorio de sucursales, y sin los
        │      clientes EXCLUIDOS a mano (las dos cosas valen también para Real)
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
-- 6. sql/cashflow_cobranzas_cliente_excluido.sql
```

**Si el 5 ya se había corrido, hay que volver a correrlo**: la vista pasó de `'FR%'` a `'[FL]%'` (ver *El universo*). Es reejecutable: recrea la vista y no pisa ningún PPP manual.

El primero crea `RO_T_CASHFLOW_COBRANZAS_PARAM_DESC` (escalas por cliente, que ya no se leen desde el script 3) y `RO_T_CASHFLOW_COBRANZAS_CLIENTE_CONFIG` (que nunca se leyó). **No** agrega `PPP_MANUAL` a `RO_T_PARAMETROS_DESC_CLIENTES`: esa columna existe en la base pero ningún script de este repo la crea, y desde el script 5 tampoco se lee.

El segundo parte la fila `COBRANZAS_FR` del tablero en sus dos componentes. Es reejecutable y no borra nada.

El tercero crea `RO_T_CASHFLOW_COBRANZAS_ESCALA_DESC` y siembra la escala de descuento general. El cuarto crea `RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL`, donde viven las fechas de cobro cargadas a mano. Los dos son reejecutables. Sin el tercero, todas las facturas proyectan con **0% de descuento**; sin el cuarto, la fecha de cobro siempre sale del PPP y la celda editable no guarda nada — ninguno de los dos rompe la pantalla.

El quinto crea la vista `RO_V_CASHFLOW_PPP_GRUPO` (sobre `dbo.GC_VIEW_PPP`, que tiene que existir) y la tabla `RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO` del PPP manual por grupo, y migra a ella los PPP manuales por cliente que había. Sin él, el PPP calculado queda vacío, la tarjeta de Parámetros avisa qué script falta y la proyección cae al respaldo (`DIAS_PP_MAX` del cliente, o 30).

El sexto crea `RO_T_CASHFLOW_COBRANZAS_CLIENTE_EXCLUIDO`, la exclusión manual de clientes (ver *Excluir un cliente*). Sin él nadie está excluido —que es lo cierto— y el switch de la tarjeta queda deshabilitado con un aviso que dice qué script falta.

---

## Las Dos Matrices (Solapas Principales)

La interfaz de `Cobranzas FR` cuenta con dos pestañas de navegación dedicadas en la cabecera superior:

### 1. Solapa "Real a Cobrar"
- **Origen de datos:** Propuestas de pago de franquicias (`FP_propuestas_pago` en la base `apps`), de las franquicias habilitadas en el directorio y sin los clientes excluidos a mano (ver *El universo*).
- **Criterio:** Facturas comprometidas por fecha efectiva de cobro pactada (`fecha_propuesta_pago`), **únicamente de propuestas en estado `ACEPTADA`**.
- **Estilo:** Visualización estándar en verde/azul (`.badge-cobro`).
- **Serie del Tablero:** `COBRANZA_REAL`.

### 2. Solapa "Pendientes Proyectados"
- **Origen de datos:** Comprobantes tipo `FAC` en estado `PEN` desde `GVA12` en `central` (Tango Gestión), de clientes `[FL]%` habilitados en el directorio y sin los excluidos a mano (ver *El universo*).
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

### Y se mantiene con el universo filtrado

Las dos solapas se filtran por el directorio de sucursales y por la exclusión manual (ver más abajo). **Los dos filtros son por cliente y se aplican igual a las dos consultas**, así que un cliente queda afuera de las dos solapas o de ninguna, y dentro del universo la partición estado por estado no se mueve. Si una solapa filtrara y la otra no, la factura de una franquicia dada de baja en una propuesta pendiente de aprobación aparecería sólo en *Pendientes Proyectados*: la plata de ese cliente estaría a medias en el tablero. `tests/test_cobranzas_fr_split.php` lo prueba comprobante por comprobante con el filtro aplicado.

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
   - Ventana: recibos de los últimos **100 días**, clientes `[FL]%` (las franquicias, ver *El universo*). La vista no mira el directorio de sucursales ni la exclusión manual: el PPP mide cómo paga el grupo.
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

## El universo: franquicias `[FL]%` habilitadas en el directorio

**Las franquicias son los clientes cuyo código empieza con F o con L** (`COD_CLIENT LIKE '[FL]%'`). Los `L` son locales con **gestión asistida**, un modelo nuevo; hasta que existieron, la condición era `'FR%'`. Todas se cargan en `SUCURSALES_LAKERS`, el directorio de sucursales del servidor `locales`.

**Cobranzas Franquicias trabaja sólo con las habilitadas en ese directorio**, y en todo el circuito por igual: la tarjeta de Parámetros, *Real a Cobrar*, *Pendientes Proyectados*, `getCobranzasFRTotales()` y las series del tablero. Una franquicia dada de baja con facturas abiertas **ya no se proyecta**. Antes sí: la regla era que el filtro era sólo de la tarjeta, y una franquicia de baja seguía sumando en el tablero.

```
SUCURSALES_LAKERS
WHERE CANAL IN ('FRANQUICIAS', 'FRANQUICIAS GA')   -- DirectorioFranquicias::CANALES
  AND NRO_SUC_MADRE IS NULL
-- cruzado por COD_CLIENT, en PHP
```

- **`FRANQUICIAS GA` es el canal de los locales de gestión asistida.** Hoy es uno solo, `LALOMA` (Lomas de Zamora). Con `CANAL = 'FRANQUICIAS'` a secas quedaba afuera como "no cargado en el directorio", que era falso.
- **Un canal nuevo entra agregándolo a `DirectorioFranquicias::CANALES`**, y no por parecido: un `LIKE 'FRANQUICIAS%'` sumaría cualquier canal que alguien cree con ese prefijo sin que nadie haya decidido que se cobra por este circuito.
- **Un cliente con varias sucursales tiene un solo estado**: alguna habilitada → habilitado; si no, alguna dada de baja → inhabilitado; si todas tienen `HABILITADO` en NULL → *estado sin cargar*. La tarjeta muestra **sólo las sucursales habilitadas** (`FRPADU` tiene una de cada una).

### Una lectura y una regla

Todo vive en `Class/DirectorioFranquicias.php`:

| Pieza | Qué hace |
| --- | --- |
| `leer()` | Lee el directorio **una vez por pedido** —el tablero pide la cobranza tres veces por carga—, con el prefijo de `Conexion::prefijoLocales()`. Trae **también las filas inhabilitadas**: sin ellas no se distingue una baja de un cliente que nadie cargó |
| `armar()`, `mapaSucursales()` | El estado de cada cliente y sus sucursales habilitadas. Puros |
| `filtrarUniverso()` | **La única implementación de la regla.** La usan la tarjeta (un ítem por cliente) y la cobranza (un ítem por factura). Pura |
| `avisosAfuera()` | Los avisos de lo que quedó afuera. Puro |

Antes la lectura era un método privado de `Parametros` y la proyección no filtraba. Con dos lecturas, la tarjeta podría listar a un cliente que Cobranzas FR no proyecta.

**El cruce se hace en PHP, después de leer**: el directorio está en otro servidor, y un JOIN entre servidores con collation distinta es justamente lo que el módulo evita. Por eso `getCobranzasFRTotales()` agrupa la cobranza real **por fecha y cliente** y no sólo por fecha. Se verificó contra la base que el total por fecha da **exactamente lo mismo** con los dos agrupamientos (12 fechas, diferencia 0, $ 318.349.088,59 los dos).

### Informar de más antes que vacío

Si el servidor `locales` no responde, o el directorio no devuelve **ninguna franquicia habilitada** —que es un problema de la consulta y no un hecho—, **no se filtra**: entran todas las `[FL]%` de Tango, con un aviso **WARNING** en la tarjeta, en Cobranzas FR y en el tablero que dice que los números pueden estar de más. Una tarjeta vacía o una cobranza en cero por una caída ajena es peor.

### Lo que queda afuera se avisa, cliente por cliente

Las facturas que no se traen salen del tablero, y eso no puede pasar en silencio. Cobranzas FR y el tablero (vía `IngresosProvider`) llevan un aviso **INFO** por caso, que **nombra a cada cliente con su importe** —de mayor a menor— para que Tesorería pueda revisarlos:

| Caso | Qué significa | Quién lo resuelve |
| --- | --- | --- |
| Inhabilitada | Dada de baja en el directorio | Nadie: es la regla |
| Estado sin cargar | `HABILITADO` en NULL. Probablemente un error de carga y no una baja | Quien carga el directorio, si sigue operando |
| Sin directorio | Cliente `[FL]%` de Tango que no está en `SUCURSALES_LAKERS` | Falta darlo de alta en el directorio |

**Cuenta sólo lo que hubiera entrado al tablero**: facturas fuera de propuesta y dentro del techo de 180 días de las vencidas, con su importe neto. Una factura de hace un año no entraba de ninguna forma, y avisarla como "sale del tablero" sería mentir.

**Las fechas manuales no se tocan.** Una factura que queda afuera conserva su fecha pactada, y si el cliente se vuelve a habilitar la fecha aplica sola.

### Lo que movió, medido

Contra la base, el 07/10/2026, el mismo momento con el código anterior y con éste:

| | Antes | Después |
| --- | ---: | ---: |
| Pendientes Proyectados | 1.052 facturas · $ 1.416.165.106,05 | 949 facturas · $ 1.294.702.482,94 |
| Real a Cobrar | $ 318.349.088,59 | $ 318.349.088,59 (todas habilitadas) |
| Clientes en la tarjeta | 84 | 85 (entra `LALOMA`) |

- **Salen** 185 facturas por $ 198.465.750,18 de **7 inhabilitadas**: FRGIUR ($ 45.959.884,19), FRVDEV ($ 38.705.235,55), FRVUR ($ 29.093.086,72), FRRMEJ ($ 27.803.157,13), FRVPA2 ($ 27.200.469,88), FRCA2 ($ 15.075.294,23) y FRMAR ($ 14.628.622,48).
- **Sale** 1 factura por $ 803.925,00 de `FRVPOU`, que **no está en el directorio**.
- **Entran** 83 facturas por $ 77.807.052,07 de `LALOMA`, el primer local de gestión asistida.
- La tarjeta deja afuera 121 clientes `[FL]%` de Tango: 22 inhabilitados, 9 con el estado sin cargar y 90 que no están en el directorio (casi todos sin facturas abiertas).

---

## La tarjeta de Parámetros

**Gestión de Cobranza Franquicias** muestra una fila por grupo empresario y, debajo, sus clientes: las franquicias del universo de arriba, con su número y nombre de sucursal. El pie dice cuántos clientes `[FL]%` de Tango no se listan y por qué. Lo que quedó afuera **no se avisa en la tarjeta**, que lista clientes y no facturas: los importes se avisan en Cobranzas FR y en el tablero, que es donde esa plata falta.

### Buscador, grupos y orden

- **El buscador va con las acciones del header**, junto a *Expandir todo*, *Exportar* y *Actualizar*: pegado a una descripción de varias líneas quedaba a media altura. El header sigue bajando de renglón en pantallas angostas.
- **Cada grupo se expande y se contrae** con el chevron de su primera celda. El clic en el resto de la fila no hace nada: ahí está el input del PPP manual.
- **Un solo botón para todos**, con el criterio de `cfBtnGrupos` del tablero: dice *Expandir todo* mientras quede alguno cerrado y *Contraer todo* si están todos abiertos.
- **Abre siempre contraída, y no guarda preferencia.** Son más de ochenta grupos y se viene a tocar uno. El estado vive **en memoria**: guardar un PPP o un medio de pago redibuja la tabla y no cierra lo que el usuario abrió. No va a `localStorage` porque un default guardado ahí sería indistinguible de una elección.
- **Los clientes se esconden, no se sacan** (`.pc-oculta`, `display: none`): *Exportar* baja lo que se ve, como en el tablero.
- **Buscando**, todo grupo con coincidencias se ve **abierto**: con los clientes que coinciden, o con todos si coincide el grupo por su código o su nombre. La búsqueda tiene **su propio mapa** de abiertos, así que al vaciar el buscador cada grupo vuelve solo a como estaba. El chevron, buscando, cierra un grupo sólo para esa búsqueda.
- **Ordenar no despega los clientes de su grupo**: las filas de cliente llevan `data-orden-sigue` y viajan pegadas al grupo, también ocultas cuando está contraído.
- **La fila del grupo deja ver lo importante contraída**: cuántos clientes tiene y, si los hay, cuántos están excluidos. Esos indicadores no entran en la búsqueda.

---

## Excluir un cliente

Poder decir **"las facturas de este cliente no van"** sin depender del directorio: un cliente en gestión judicial, uno que refinancia por fuera, uno que se cobra por otro lado. El directorio dice si el local **opera**, no si se le **cobra**, y es de otra gente.

**Es por cliente y alcanza a todas sus facturas**, las emitidas y las que vengan. La toma una persona, **con motivo**.

### Dónde se decide

En la tarjeta de Parámetros, columna **Excluir**:

- **Al prenderlo** se pide el motivo en un diálogo. Sin motivo no se confirma, y el servidor lo valida de nuevo (`CobranzasExclusion::validarMotivo()`; la tabla además tiene un `CHECK`). Se valida también que el cliente sea `[FL]` y exista en `GVA14`.
- **Al apagarlo** se pide confirmación.
- **Un cliente excluido se ve atenuado**, con un ícono cuyo tooltip dice el motivo, quién y cuándo (`Js/auditoria.js`).
- **Permiso:** edición de Parámetros › Cobranzas. Sin permiso el switch no se dibuja; los endpoints `excluirClienteCobranza` e `incluirClienteCobranza` están en el mapa de `AuthCashflow` y responden 403.
- **Sin la tabla** (script 6 sin correr), el switch queda deshabilitado y un aviso dice qué script falta.

**Historial sin bajas físicas**, como Proveedores y Echeqs: volver a incluir marca `VIGENTE = 0` con quién y cuándo (`USUARIO_BAJA` / `FECHA_BAJA`), y excluir de nuevo inserta otra fila. Un índice único filtrado deja una sola vigente por cliente. **Excluir lo ya excluido es un error**, no un cambio de motivo: para cambiarlo se incluye y se vuelve a excluir, y quedan las dos decisiones.

**Excluir no cambia el PPP.** El cliente sigue en su grupo y el PPP calculado lo sigue contando: mide cómo paga el grupo, no si se le cobra.

### Qué pasa en Cobranzas FR y en el tablero

**Las facturas de un cliente excluido salen de las dos solapas y de todo lo que suma**: las columnas del eje, la columna Total, el pie, los KPIs, `getCobranzasFRTotales()` y las series `COBRANZA_REAL`, `COBRANZA_PROYECTADA` y `COBRANZA`. Se separan en PHP, después del filtro del universo, con `CobranzasExclusion::separar()`.

**No se descartan: quedan aparte.** El controller arma el payload sin ellas y, con la misma función y el mismo filtro de emisión, un segundo payload con sólo las excluidas (`filas_excluidas`). Las excluidas **no están en la lista que se suma**, así que no pueden sumar ni por error.

- **"Ver excluidos"**, en la barra de Cobranzas FR, apagado por defecto. Vale en las dos solapas y en Resumen y Detalle Facturas. Prendido, las facturas excluidas se dibujan **atenuadas y tachadas**, con el ícono del motivo, quién y cuándo, y **no suman** en nada. En Detalle Facturas su fecha de cobro es de sólo lectura.
- **El cartel de arriba se ve siempre** que haya excluidas, con el switch apagado también: facturas, clientes, importe y motivos. Es la única forma de notar que hay plata afuera. Mismo criterio que `excluidosEch` de Echeqs.
- **El tablero** lleva un aviso INFO con el importe excluido y los motivos.

**Exclusión e inhabilitadas son independientes.** La exclusión se aplica sobre el universo: una franquicia inhabilitada ya no se trae, así que no hace falta excluirla.

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
- **La fecha manual sobrevive a la factura.** No se limpia cuando el comprobante sale del listado —se cancela, se paga, entra en una propuesta, o su cliente sale del universo porque lo inhabilitan en el directorio o lo excluyen a mano—. Queda guardada y vuelve a aplicar sola si reaparece. Borrarla automáticamente perdería una decisión que alguien tomó, y el síntoma sería una fecha que "se desconfigura sola".
- **La celda editada distingue lo pactado de lo estimado.** Sin esa marca, dos filas con la misma fecha en pantalla estarían diciendo cosas distintas y no habría forma de saber cuál es cuál. El botón de volver borra el override y la fecha vuelve al PPP.
- **Al guardar se recarga la pestaña entera**, no la fila. La fecha cambia los días, el descuento, el neto, en qué columna del eje cae ese importe y los totales del pie: parchearlo en el navegador sería reimplementar en JS la cuenta que ya hace el backend, con el riesgo habitual de que las dos den distinto.

En **Resumen** la fila es un cliente y no un comprobante, así que no hay nada que editar: se muestra un indicador cuando alguna de sus facturas tiene fecha cargada a mano, y el detalle está en Detalle Facturas.

Los endpoints son `IngresosController?action=saveFechaCobroManual` y `deleteFechaCobroManual`; los del gesto masivo, `saveFechaCobroManualMasiva` y `deleteFechaCobroManualMasiva` (ver abajo).

### De a una, o de a muchas

> El gesto masivo es **nuevo** (`feature/cobranzas-fecha-masiva-orden`). La celda editable no cambió.

El caso real casi nunca es una factura: es *"este cliente paga todo el 30"*, que son las ocho facturas que tiene abiertas. Es el gesto de Proveedores Locales (ver *La fecha se carga de a una, o de a muchas* en `README-proveedores-locales.md`): **se seleccionan, se lee cuántas son y por cuánta plata, se elige la fecha, y recién ahí se guarda.**

**Dónde.** Sólo en *Pendientes Proyectados › Detalle Facturas* y con permiso de edición, que es donde ya se edita de a una. En *Real a Cobrar* la fecha sale de la propuesta aceptada; en *Resumen* la fila es un cliente.

**La columna de selección va primera y está siempre en el DOM.** Se esconde con CSS donde no se puede seleccionar —Real a Cobrar, Resumen, sin permiso—: la tabla prende `.con-seleccion` sólo en la vista editable. Si se dibujara sólo ahí, los `nth-child` del modo Resumen y los índices de las columnas fijas cambiarían según la solapa. Es lo mismo que hace `tablaCorp` de Pagos con Tarjetas. No se ordena (`data-orden="no"`) ni se exporta (`data-exportar-omitir`), y sin permiso los checks no se dibujan. Los índices que movió están en *La columna TIPO se fue*, más abajo.

**La barra** aparece cuando hay algo elegido y dice cuántas facturas, el **importe neto**, de cuántos clientes y cuántas tienen fecha manual. Dos acciones:

| Botón | Qué hace |
| --- | --- |
| *Poner fecha de cobro* | La misma fecha para todas. El diálogo (`Notificacion.pedirFecha`, con `min` en hoy) dice cuántas, por cuánto y de cuántos clientes, **cuántas ya tenían una fecha manual** —se pisan— y que **la fecha cambia los días, el tramo de descuento y el neto**: el importe de la barra es el de hoy. Si todas comparten la misma fecha, arranca con ella |
| *Volver a la fecha calculada* | Borra la fecha manual de las seleccionadas **que la tienen** —las otras no cuentan— y vuelven a emisión + PPP. Pide confirmación con el número. Sin ninguna con fecha manual, el botón se apaga |

- **No se aceptan fechas pasadas, por el mismo motivo que de a una**, y el servidor lo valida de nuevo. El diálogo además no deja confirmar una fecha anterior tipeada a mano (ver `README-cashflow.md`, *Notificaciones*).
- **Las excluidas no se seleccionan.** Con *Ver excluidos* prendido se ven, pero sin check: no están en la lista que suma, y su fecha es de sólo lectura.
- **La selección sobrevive a los redibujos**: el buscador, el filtro por fecha de emisión y el cambio de vista del eje. Es un mapa de claves `T_COMP|N_COMP`, y **lo que se cuenta y se manda es lo seleccionado que se ve**, así que una factura escondida por el buscador o por el filtro no se fecha sin que nadie la vea.
- **Se poda sólo con una carga de la vista editable sin filtro de emisión.** El filtro es del servidor: lo que deja afuera no viene en la respuesta, y podar contra eso borraría la selección cada vez que se filtra. Lo que sí sale es lo que dejó de existir —una factura cobrada, en una propuesta, o de un cliente excluido—.
- **Al fechar, la selección no se limpia**, como en Proveedores: las facturas siguen a la vista, movidas de columna, y así se puede corregir ahí mismo. Al guardar se recarga la pestaña entera, igual que de a una.

**Una sola transacción, escrita una sola vez.** O se fechan todas o ninguna. Los cuatro gestos —guardar y borrar, de a una y de a muchas— pasan por el mismo lote:

```
saveFechaManual ─────────┐                                  ┌─ escribirFechaManual   (UPDATE / INSERT)
saveFechaManualMasiva ───┴─ guardarLoteFechas ─┐            │
                                               ├─ enLoteFechas
deleteFechaManual ───────┐                     │            │
deleteFechaManualMasiva ─┴─ borrarLoteFechas ──┘            └─ borrarFechaManual     (DELETE)
```

- `normalizarClavesCobro()`, estática y pura, resuelve las claves **antes de abrir nada**: `T_COMP|N_COMP` sin repetidos —la clave de la tabla no incluye al cliente—, el cliente al lado y opcional como siempre fue de a una, y el lote vacío o un renglón sin comprobante se rechazan.
- **La fecha se valida una vez, antes de abrir nada**, con el mismo `validarFechaCobroManual()`.
- **La edición de a una es un lote de uno**: misma firma, mismos mensajes, mismo camino de escritura. Antes de este cambio no tenía transacción; no la necesitaba, y ahora la tiene sin costo.
- *Volver a la fecha calculada* devuelve **cuántas se borraron de verdad** (`sqlsrv_rows_affected`), que es lo que dice el mensaje.
- Los dos endpoints nuevos están en el mapa de `AuthCashflow` **con los mismos destinos que la edición de a una**: poder fechar una y no muchas no protegería nada.
- **Es la misma tabla** y no hay script nuevo que correr.

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

### Y el Resumen abre con los clientes por su cobro más próximo

Como el navegador no ordena el Resumen, abría en el orden del agrupado. Ahora
**el orden lo trae el servidor**: los clientes por su fecha de cobro más
próxima, ascendente, con `COD_CLI` de desempate. Si el usuario ordena por otra
columna, manda su elección, como siempre.

- Lo arma `EjeVista::ordenarPorFechaMasProxima()`, pura, sobre los items de a
  uno —el agrupado descarta la fecha cuando difiere dentro del cliente—, y se
  aplica en `payloadCobranzas()`: las dos solapas, sus excluidas y Cobranzas May.
- **"Más próxima" es la mínima, tal como se dibuja.** Una vencida reubicada
  cuenta como el primer día del eje, y una fecha pactada a mano que ya pasó
  conserva la suya y va primera. Así **cada cliente queda donde aparece su
  primera factura en Detalle Facturas**: verificado contra la base, el orden de
  los dos coincide exactamente en Pendientes Proyectados (82 clientes), Real a
  Cobrar (13) y Mayoristas (100).
- Un cliente sin ninguna fecha va al final, que es donde `tabla-orden.js` pone
  lo vacío.

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

**Y con la columna de selección se movieron todos de nuevo**, ahora un lugar para adelante: entró **primera** (ver *De a una, o de a muchas*).

| Qué | Sin Tipo | Con la selección |
| --- | --- | --- |
| `porDefecto` de `crearColumnasFijas()` | `[0, 1]` | `[1, 2]` — siguen siendo COD_CLI y RAZON_SOC; el check no queda fijo |
| Clave de las columnas fijas | `cobranzas_fr.sin_tipo` | `cobranzas_fr.con_seleccion` — un `[0, 1]` guardado fijaría el check y COD_CLI. **Quien tenga columnas fijas elegidas vuelve una vez al default** |
| `colspan` del `TOTALES` del `tfoot` en el HTML | `9` | `11` — lo reemplaza el JS al primer dibujo |
| Pie que arma el JS | rótulo en la primera celda | **una celda vacía de selección** y el rótulo en COD_CLI |
| `.modo-resumen` — columnas de detalle | `nth-child(3..7)` | `nth-child(4..8)` |
| `.modo-resumen` — columna `Cobro` | `nth-child(10)` | `nth-child(11)` |

Las reglas de `.modo-resumen` quedaron además **acotadas a su tabla** (`#tablaCobranzasFR.modo-resumen`): Cobranzas May usa la misma clase con otros índices. Y la prueba ya no repite los números: `tests/test_tablas_controles.php` **deriva los índices del marcado de la pestaña** y verifica que cada regla esconda la columna que dice, en encabezado, cuerpo y pie.

Y una que no es un índice: **`TIPO_REGISTRO` salió del buscador**. `filtrarTabla()` esconde
filas mirando el `textContent` de la fila, y desde que no hay columna Tipo el texto "REAL"
o "PROYECCIÓN" no está en el DOM. Si siguiera en `filasFiltradas()`, buscar *real* dejaría
los totales de esas filas y escondería las filas: el pie no cerraría con la tabla. Las dos
funciones tienen que mirar lo mismo.

En Resumen se van `FECHA`, `T_COMP`, `N_COMP`, `Desc`, `Días` y `Cobro`: son distintos en cada comprobante del cliente. `armarAgrupado()` **descarta** los campos que difieren dentro del grupo en vez de mostrar el del primer comprobante — una fila que dijera "FAC 0001-123" cuando en realidad son doce facturas es peor que una celda vacía, porque nadie tendría por qué sospecharlo.

El pie de totales pasó a tener **una celda por columna descriptiva** en lugar de un `colspan` escrito en duro, que había que actualizar a mano cada vez que cambiaba la cantidad de columnas.

**Arriba de cada fecha va su total**, en la fila donde estaba la leyenda *Días* / *Meses*, y arriba de *Total* el total general. Son la misma cuenta del pie —`totalesEje()`, sobre `filasFiltradas()`— con el mismo formato, así que siguen al buscador, a *Ver excluidos*, a la solapa, al modo y a la vista exactamente como el pie. Los pinta `Js/eje-totales.js` (ver `README-cashflow.md`). Los totales no movieron las reglas de `.modo-resumen`: van **después** de las descriptivas, así que esos `nth-child` siguen apuntando a las mismas celdas (hoy `4..8` y `11`, desde que entró la columna de selección).

---

## Integración con el Tablero de Cashflow

El proveedor `IngresosProvider` registra tres series en `CashflowRegistry`:

| Código Proveedor | Serie | Descripción | Fila en Cashflow |
| --- | --- | --- | --- |
| `COBRANZAS_FR` | `COBRANZA_REAL` | Cobranza real de franquicias | `COBRANZAS_FR_REAL` — activa |
| `COBRANZAS_FR` | `COBRANZA_PROYECTADA` | Cobranza proyectada pendientes (PPP) | `COBRANZAS_FR_PROY` — activa |
| `COBRANZAS_FR` | `COBRANZA` / `COBRANZA_TOTAL` | Total franquicias (Real + Proyectada) | `COBRANZAS_FR` — **inactiva**, ver arriba |

Las tres series salen de `getCobranzasFRTotales()`, con **el mismo universo y la misma exclusión que la pestaña**, sin excepción: un tablero que cuenta plata que la pestaña no muestra no se puede auditar. `IngresosProvider` deja los avisos del universo (WARNING si el directorio no respondió, INFO por lo que quedó afuera) y el de los excluidos a mano. Los lee después de la lectura `'todos'`, que cubre las dos solapas: cada lectura empieza de cero, así que nada se cuenta dos veces.

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
- La tarjeta de Parámetros: el cruce con el directorio de sucursales (`DirectorioFranquicias::mapaSucursales()` concatena varias sucursales del mismo cliente; `filtrarUniverso()` deja afuera, contados, a los que no están, y sin directorio —caído, vacío o sin ninguna habilitada— muestra todos **con** aviso) y el agrupado en una fila por agrupador con sus clientes ordenados (`agruparPorAgrupador()`).
- El universo: el estado de cada cliente (habilitada, `L` de gestión asistida, inhabilitada, `HABILITADO` NULL, una sucursal de cada, baja más NULL, sin directorio), el filtro de facturas con lo que queda afuera por caso, las filas agrupadas de los totales, el directorio caído, los tres avisos con sus nombres e importes y el resumen después de quince clientes, y que todas las lecturas de franquicias —las de `Ingresos` y la vista— usen `[FL]%`.

`tests/test_cobranzas_exclusion.php`:
- El motivo vacío se rechaza; sólo se excluyen franquicias.
- Las facturas de un excluido salen de lo que suma —eje, KPIs— y quedan aparte, marcadas con motivo, quién y cuándo; en Resumen el cliente es una fila entera excluida.
- El cartel y el aviso del tablero: facturas, clientes, importe y motivos.
- Que las tres lecturas y las tres series apliquen la exclusión, y que con "Ver excluidos" se dibujen sin sumar y con la fecha de sólo lectura.
- Volver a incluir deja historial (sin `DELETE`, índice único filtrado), y sin la tabla nadie está excluido y excluir falla con el script que falta.
- El permiso en el mapa de `AuthCashflow`, y que el PPP del grupo no cambie al excluir.

`tests/test_cobranzas_fecha_masiva.php` (también cubre Cobranzas May):
- Las claves del lote: normalizadas, sin repetidos, el cliente opcional; lote vacío y renglón sin comprobante rechazados.
- El servidor rechaza la fecha pasada, la que no existe y el lote vacío **antes de conectarse**; la fecha y las claves se validan antes de abrir el lote.
- Que haya **una sola transacción** en `Ingresos`, que la usen los cuatro gestos, y que la tabla se escriba en dos métodos y nada más.
- **Todo o nada, con una falla simulada en el segundo comprobante**: la subclase de prueba reemplaza la conexión y las dos escrituras y escribe **sólo en una `#temporal` de la sesión**. La prueba verifica que no nombre ninguna tabla real ni llame a las escrituras originales, porque la base es la de producción. Se comprobó que falla si se saca el rollback.
- Que *volver a la calculada* cuente sólo las que tenían fecha, y que la edición de a una siga igual: firma, mensajes, endpoints.
- Los endpoints masivos en el mapa de `AuthCashflow`, con los destinos de la edición de a una.
- El diálogo no deja confirmar una fecha anterior al mínimo.
- El cableado de punta a punta en las dos pestañas: la barra y sus botones dentro del permiso y enganchados, la columna primera con `data-orden="no"` y `data-exportar-omitir`, el CSS que la esconde fuera de la vista editable, la poda sin filtro, la clave nueva de columnas fijas.

`tests/test_cobranzas_proyeccion.php`, además: el orden del Resumen por la fecha más próxima, con desempate, sin fecha al final, y que `payloadCobranzas()` lo aplique.

`tests/test_tablas_controles.php`:
- Que `.modo-resumen` esconda exactamente las columnas de detalle y `Cobro`, en FR y en May, con los índices derivados del marcado; y que ninguna regla quede sin acotar a su tabla.
- La tarjeta de franquicias: el botón de expandir todo cableado de los dos lados, los clientes ocultos con `display: none`, sin `localStorage`, y `data-orden-sigue` en las filas de cliente.

`tests/test_cobranzas_fr_split.php`:
- Que lo que cuenta *Real* y lo que la proyección excluye sean complementarios, estado por estado, **también con el filtro de franquicias habilitadas aplicado**: un comprobante de una habilitada se cuenta una vez, uno de una inhabilitada ninguna, y queda contado afuera.
- Que la estructura partida valide contra el registro real, y que reactivar la fila total no.

```bash
php cashflow/tests/run.php
```

