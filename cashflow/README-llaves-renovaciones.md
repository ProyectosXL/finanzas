# Módulo Llaves y Renovaciones — cuánto se paga de llave, renovación, obra y apertura, y cuándo

Pestaña **RRHH y Operativos → Llaves y Renov. Contratos** (sub-pestañas *Proyección*, *Contratos* y *Facturas*), la sub-pestaña **Parámetros › Llaves y Renovaciones**, la acción **«Es llave / renovación»** en *Alquileres › Facturas* y el proveedor `LLAVES_RENOV` del tablero, en una fila agrupada en **real** y **proyectado**.

Rama: `feature/llaves-renovaciones`. Reemplaza las hojas *LLves y Renov Contratos* y *Detalles de vencimientos* del Excel de Flujo de Fondos de Tesorería.

---

## La idea en una línea

**La llave de cada renovación de contrato sale del contrato nuevo cargado en la app de alquileres; si todavía no está, se estima con el criterio de Costo de Ocupación —% × (1 + 2 + 8 del mes, como lo proyecta Alquileres) × meses— y se paga en cuotas. Los otros gastos van en la firma, las obras y aperturas en dólares, lo pactado tal cual, y una factura marcada como llave reemplaza todo lo proyectado de su local, concepto y renovación.**

```
App de alquileres (central, SÓLO SE LEE)      Alquileres (cashflow)            Parámetros › Llaves (cashflow)
  RO_T_CONTRATOS_ALQUILERES                     alquilerMensual(): 1+2+8 neto    generales (25 %, 4 cuotas, día 15,
    vigencia, llave (4), comisiones (5),        operador = razón principal         cortes por m², U$S por segmento)
    FPC (18)                                    % retención del local            por operador (% llave, % otros,
Gestor de locales (XL-APPS, SÓLO SE LEE)        Facturas: «Es llave»               escribano, % impuestos, cuotas,
  FP_T_SUCURSALES_MEDIDAS (m²)                    (LlavesFacturas, script 68)       corrimiento)
Tango (CPA04, SÓLO SE LEE)                                                       por local (por el contrato que vence)
  facturas de llave: % de impuestos sugerido                                     ya pagadas, obras, aperturas,
DolarFuturo (curva ROFEX)                                                        compromisos a mano
        │                                 │                                    │
        └──────────────┬──────────────────┴────────────────────────────────────┘
                       ▼
              LlavesCalculo::proyectar()  ── LA regla, pura: una lista de pagos con su origen
                       │                     (Contrato cargado · Estimado · Manual · Factura)
                       ├──► pestaña (Llaves::armarPestana(): agrupa, no calcula)
                       └──► LlavesProvider: REAL (facturas) + PROYECTADO (el resto), por día ──► tablero
```

**El cashflow no escribe en la app de alquileres ni en el gestor de locales.** Sólo los lee, y lo fija una prueba (`AlquileresApp`, `LocalesMedidas` y `LlavesTango` no tienen sentencias de escritura).

**Sólo Argentina** (conexión `central`).

---

## Ejecución de los scripts

| # | Script | Base | Qué hace | Si no se corre |
| --- | --- | --- | --- | --- |
| 66 | `sql/cashflow_llaves.sql` | `central` | Las siete tablas de Parámetros › Llaves y Renovaciones (generales, por operador, por local, ya pagadas, obras, aperturas, compromisos) con auditoría e historial. **Siembra sólo los tres defaults de Generales** (25 % de llave, 4 cuotas, día 15) | La pestaña y Parámetros **cargan** y avisan en crítico nombrando el script; no se estima nada y Parámetros no deja guardar. El resto del cashflow funciona como hoy |
| 67 | `sql/cashflow_llaves_permisos.sql` | `apps` | Crea, si no existen, `cashflow.tab.llaves_renov`, `cashflow.editar.llaves_renov` y `cashflow.editar.parametros.llaves_renov`, colgadas del mismo módulo que el resto de `cashflow.tab.%`. **No asigna ningún rol** | Sólo el administrador ve la pestaña y edita Parámetros › Llaves y Renovaciones |
| 68 | `sql/cashflow_llaves_facturas.sql` | `central` | La marca «es llave» de una cuota de alquiler y sus N pagos (`RO_T_CASHFLOW_LLAVES_FACTURA` y `..._PAGO`), con índice único filtrado por cuota vigente | El botón *Es llave / renovación* no se dibuja; las facturas siguen como hoy (sin imputar o excluidas en Alquileres) |
| 69 | `sql/cashflow_llaves_fila.sql` | `central` | Reemplaza la fila *Llaves y Renovaciones* (`LLAVES_RENOV/PAGOS`, ID 17, activa) por dos filas agrupadas, `REAL` y `PROYECTADO`, en una transacción, con el control de doble conteo al pie | La fila de antes sigue activa y muestra el total, sin abrir |

El 66 y el 68 van después de los de Alquileres (51 y 53); el 69, después de `sql/cashflow_estructura_grupos.sql`. El 67 es independiente. **Orden recomendado: 66 → 67 → 68 → 69.**

**El tablero se mueve con el código, no con los scripts**: `LLAVES_RENOV` pasa a disponible y la fila 17 (activa, en cero hasta hoy) empieza a sumar. Sin el 66 suma cero; con el 66 y sin % de impuestos cargado por operador, la llave tampoco se proyecta (ver *El tablero*, abajo).

### Después de correrlos

1. Dar `cashflow.tab.llaves_renov` y, a quien corresponda, `cashflow.editar.parametros.llaves_renov` (Parámetros) y `cashflow.editar.llaves_renov` («Ya pagada» desde la solapa Contratos) desde Gestión de Usuarios. Marcar una factura como llave pide `cashflow.editar.alquileres`, como excluirla.
2. En *Parámetros › Llaves y Renovaciones*: confirmar los **cortes de segmento** sugeridos, cargar los **costos en U$S**, y por operador el **% de impuestos** (el sugerido está en gris), el **% de otros gastos** y el **escribano** (0 si no hay).
3. Marcar **«Ya pagada»** las renovaciones que ya salieron de caja y no están en la app: al 23/11/2026, **Distrito Arcos** (cuota inicial L78 $ 180,5 M, pagada el 31/08/2026) y **Abasto** (cuota inicial L7 $ 92,75 M, 31/08/2026). Y, si corresponde, las de contratos ya cargados que se pagaron antes de su inicio: **DOT #91** ($ 99,6 M, 31/08/2026) y **Alto Palermo #90** ($ 44,5 M, 31/08/2026). Administración resuelve Arcos y Abasto cargando el contrato nuevo en la app; la marca sigue valiendo.

---

## 1. El universo: los contratos de cada local

`AlquileresApp::contratosConImportes()` (no cambia lo que devuelve `contratos()`): por local, cada contrato con su vigencia, **llave** (`ID_CA = 4` / `IMPORTE`), **comisiones** (`ID_CA_2 = 5` / `IMPORTE_2`) y **FPC lanzamiento** (`ID_CA_3 = 18` / `IMPORTE_3`).

- **Por qué en `AlquileresApp` y no en una clase propia**: es la única clase que lee la app de alquileres, y una prueba fija que no escribe. Un segundo lector repetiría esa garantía, y el día que la app cambie una columna habría dos lugares que arreglar.
- **El concepto se mira, no se supone**: si una fila trajera otro `ID_CA` en esa columna, ese importe es null.
- **`ESTADO` no nulo es una baja**: la app no lo escribe hoy (las 52 filas en NULL), pero la vista `RO_V_CONTRATOS_VALOR_LLAVE` —la de Costo de Ocupación— filtra `ESTADO IS NULL`, y acá se sigue el mismo criterio.
- Los locales son los de Alquileres con `HABILITADO = 1`: **un local cerrado en Lakers no renueva**. Los que proyectan **según vigencia** (temporales, XL Rural) quedan afuera de la estimación.
- **El operador** es la razón social principal del local en *Parámetros › Alquileres*. Sin ella, **no proyecta** y avisa con el link.

`LlavesCalculo::limpiar()` saca los duplicados exactos (MDP Aldrey #25 y #26) y ordena.

| | |
| --- | --- |
| **Vigente** | El que cubre hoy (si se superponen, el que empezó último); si ninguno, el último que venció |
| **Renovación** | El contrato que empieza después del fin del vigente |
| **Clave de una renovación** | **El contrato que renueva** (`ant`). Es la de los parámetros por local, de «Ya pagada» y de las facturas: sigue valiendo cuando administración carga el contrato nuevo |

Estados de la solapa Contratos: *Vigente*, *Vence en el horizonte*, *Vencido sin renovar*, *Renovado*, *No renueva*, *Sin contrato en la app* (Oeste) y *Temporal (proyecta según vigencia)*.

### La medición (al 23/11/2026)

- 52 contratos de 23 locales (20 habilitados). Llave > 0 en 31 contratos de 17 locales; comisiones en 42 de 21; FPC en 20 de 10.
- **Llave = 1 es una marca** (MDP Gallegos #87, Portal Rosario #81): administración la carga para que Costo de Ocupación calcule el devengado.
- Vencen en el horizonte: Distrito Arcos y MDP Gallegos (31/12/26), Alto Palermo (28/02/27), Abasto y Parque Brown (30/06/27), San Justo (31/08/27), Gurruchaga (04/09/27), Tortugas (30/09/27), MDP Aldrey (31/10/27). Vencido: Solar (30/09/26).
- **Calibración del 25 %** (llave ÷ (1+2+8 del primer mes × meses), renovaciones consecutivas): el 1 y el 2 valen 0 en todos los casos, así que la base es el VMA. IRSA 27,9 % ponderado (19,6 a 38,7), DOT 26,3 %, San Justo 24,5 %, Parque Brown 16,7 %, Paseo del Siglo 14,6 %, Caballito 9,7 %.
- **(Comisiones + FPC) ÷ llave**: IRSA 43,8 %, DOT 40,1 %, Cencosud 39,5 %, San Justo 8,4 %.

---

## 2. Parámetros › Llaves y Renovaciones

`Class/LlavesParametros.php` (lectura, escritura y validadores puros), `Class/LlavesSugeridos.php` (lo que se sugiere, puro), `Tabs/parametros_llaves.php`, `Js/Parametros-Llaves.js`. Permiso propio: `cashflow.editar.parametros.llaves_renov`; la lectura es la de Parámetros.

**Una fila vigente por clave, con historial.** Cambiar es dar de baja y dar de alta, en una transacción. Obras, aperturas, compromisos y ya pagadas no se editan: baja y alta.

**Un dato que falta es null**: vacío en el % de llave o en las cuotas es «el de arriba»; vacío en el escribano o en el % de impuestos es «sin dato» y avisa. Sólo 0 cargado es 0.

### 2.a Generales

% de llave (25), cuotas (4), día de pago de obras y aperturas (15), los **cortes de segmento** por m² totales y el **costo en U$S** por segmento para obra y para apertura. Los tres primeros los siembra el script y no se vacían.

**Los cortes se sugieren y los confirma una persona**: los terciles de los m² de los locales habilitados de hoy (`LlavesCalculo::terciles()`), al 23/11/2026 **≈ 84 y ≈ 110 m²** (17 locales con m²; faltan Avellaneda, XL Rural, Malvinas y Oeste). Mientras no se confirman, ninguna obra sin segmento a mano proyecta.

### 2.b Por operador

Los operadores son las razones sociales dadas de alta en Alquileres.

| Campo | Vacío | Sugerido (en gris, con su tooltip) |
| --- | --- | --- |
| % de llave | El general | Las renovaciones de la app de ese operador (`LlavesSugeridos::porOperador()`) |
| % de otros gastos | Sin dato: avisa | Ídem, (comisiones + FPC) ÷ llave |
| Escribano y otros ($ por renovación) | Sin dato: avisa; se proyecta la parte del % | — |
| **% de impuestos** sobre llave y otros gastos | **Sin dato: avisa y la llave de ese operador no se proyecta** | La relación factura / contrato de Tango (`LlavesSugeridos::impuestos()`): IRSA 31 %, DOT 24,5 %, Paseo del Siglo y Caballito 21 %, Cencosud 0 % (la factura «LLAVE L2» es llave + comisiones, sin impuestos) |
| Cuotas | Las generales | — |
| Corrimiento (meses respecto del inicio; negativo = antes) | 0 | — |

**Las facturas de Tango dicen cuánto se facturó, no cómo se paga.** IRSA factura la «cuota inicial» una vez y la paga en cuotas con echeqs (Avellaneda L6 $ 98,25 M: 19,65 + 19,65 + 9,825 M). Por eso de Tango sólo sale el % de impuestos sugerido; las facturas se ven como referencia en el tooltip y nunca se sugieren cuotas con ellas.

### 2.c Por local

Sólo los que tienen un contrato que vence dentro del horizonte o ya vencido, sin renovación cargada. Cuelga del **contrato que vence** (`ID_CONTRATO`): cuando se carga el contrato nuevo, deja de aplicarse.

**No renueva** (con motivo obligatorio), **llave a mano** (reemplaza el cálculo; origen *Manual*), **% propio**, **meses del contrato nuevo** (por defecto, los del último contrato con llave), **cuotas** y **mes de la primera cuota**.

### 2.d Ya pagadas

La llave o los otros gastos de una renovación que **ya salieron de caja**: fecha, monto, comprobante opcional. Corta ese concepto en esa renovación, cargada o estimada, y **sigue valiendo cuando se carga el contrato nuevo**. No suma. Se marca desde Parámetros o desde la solapa *Contratos* (con el permiso de la pestaña).

### 2.e Obras, aperturas y compromisos

- **Obra de imagen**: local, mes, segmento (vacío = por m²), costo propio en U$S opcional, observación. Una por local y mes.
- **Apertura**: nombre, mes, segmento obligatorio, costo propio opcional; se vincula a un número de sucursal cuando exista.
- **Compromiso a mano**: operador (razón de Alquileres o proveedor del maestro de Proveedores Locales), local opcional, concepto, fecha, monto, forma de pago informativa, observación; **en serie**, N cuotas mensuales del mismo importe (el último día del mes si el día no existe). Se da de baja la cuota o la serie entera.

---

## 3. Cómo se proyecta

`Class/LlavesCalculo.php` es **la** regla, pura: hoy, contratos, alquiler, m², curva, parámetros y facturas entran ya leídos.

### 3.a Renovación cargada en la app

- **Llave**: su importe en N cuotas mensuales iguales y consecutivas (local > operador > general), la primera el día de inicio del contrato (+ corrimiento del operador), cada una corrida al hábil siguiente.
- **Comisiones + FPC**: un pago, el día de la primera cuota (la firma).
- Vale para **cualquier contrato con cuotas por delante**, también el vigente si empezó hace poco (DOT #91).
- **Llave = 1**: se estima (3.b) con los meses de ese contrato; nunca se pagan $ 1.

### 3.b Sin la renovación cargada

```
llave estimada = % × (1 + 2 + 8 del mes de la primera cuota, como lo proyecta Alquileres) × meses
```

- Sólo si **el vigente tiene llave** (> 0, la marca incluida): el criterio de Costo de Ocupación (`verificarValorLlaveNegocio()`). Gurruchaga, MDP Aldrey y Solar no estiman llave, pero sí otros gastos.
- El alquiler es el **neto** que proyecta Alquileres (`Alquileres::alquilerMensual()`, antes de las facturas). Si Alquileres no tiene ese mes para el local, **no proyecta** y avisa.
- Meses: los del local; si no, los del **último contrato con llave**; si no hay, los del vigente, con aviso si son menos de 12.
- Primera cuota: el mes del día siguiente al fin del vigente + corrimiento, o el del local. **Vencido sin renovar**: la primera cuota va al primer hábil después de hoy, con aviso de atención.

### 3.c Otros gastos estimados

`% de otros gastos del operador × llave estimada + escribano`, un pago el día de la primera cuota. El escribano es un importe final: sin impuestos ni retención.

### 3.d Impuestos y retención

`neto × (1 + % de impuestos del operador) − neto × % de retención del local`, con `AlquileresPago::importes()` —la misma regla de redondeo que Alquileres— pasándole el % de impuestos como la única alícuota. Por qué no un helper propio: así la suma, la retención sobre el neto y el redondeo a centavos están escritos una sola vez, sin agregarle a `importes()` un caso que Alquileres no tiene. Sin % de impuestos o sin % de retención, la llave y los otros gastos de ese local **no se proyectan**, y el aviso dice el neto que falta.

### 3.e Obras y aperturas

Costo en U$S (el propio o el del segmento) × `DolarFuturo::resolver()` de su fecha: el día de pago general del mes, corrido al hábil. Sin curva para ese mes, el más cercano marcado *aproximado* en el tooltip; sin curva, null con aviso. **El segmento** (`LlavesCalculo::segmento()`): hasta el corte de chico, chico; hasta el de mediano, mediano; más, grande. Los bordes son del segmento de abajo. Sin m² y sin segmento a mano, no proyecta.

### 3.f Compromisos a mano

Su fecha y su monto, tal cual (origen *Manual*).

### 3.g Lo que corta

- **No renueva**: nada de esa renovación.
- **Ya pagada**: ese concepto.
- **Una factura marcada como llave**: todo lo proyectado de ese concepto en esa renovación (parte 4).
- **Los pagos de hoy o anteriores no se proyectan.** Una cuota de un contrato firmado que ya pasó, del mes en curso o el anterior, sin factura ni marca, se avisa.

Cada pago lleva su **origen** (*Contrato cargado*, *Estimado*, *Manual*, *Factura*) y la cuenta en el tooltip: *«25% (general) × $ 18.927.137 (1 + 2 + 8 de enero de 2027…) × 41 meses = $ 194.003.154, cuota 2 de 4. Neto … + 21% de impuestos … − 1% de retención … = a pagar …»*.

---

## 4. Las facturas reales: «Es llave / renovación»

`Class/LlavesFacturas.php` (script 68), en *Alquileres › Facturas*, junto a *Excluir*, por cuota o las tildadas.

- Pide el **local**, el **concepto** (Llave / Otros gastos / Obra) y el **mes**. La llave y los otros gastos se imputan a **la renovación que empieza más cerca de ese mes** (`renovacionDeMes()`): la factura de agosto de Arcos es de la renovación de enero, no del contrato de 2023. La obra, al local y mes.
- **La factura no es el pago**: de a una factura pendiente se reparte en **N pagos con fecha e importe** (por defecto, las cuotas del operador desde su vencimiento a considerar), que suman el pendiente menos la retención; sin pagos, va entera en su vencimiento a considerar. Con varias tildadas, cada una va entera.
- **`AlquileresFacturas::destino()` devuelve `A_LLAVES`**: la cuota sale de Alquileres y **no vuelve a Proveedores Locales**. La misma regla para todos (Alquileres, Proveedores Locales, Llaves y el tablero), y lo fija una prueba. Con la condición de seguridad de Alquileres sin cumplir, todo queda en Proveedores Locales, como siempre.
- En Llaves, **reemplaza todo lo proyectado de ese local y concepto en esa renovación** —manda lo real, como la imputación de Alquileres (parte 13)— y suma como **real** en sus pagos (un pago vencido, al siguiente hábil desde hoy).
- **Ya pagadas**: con *Ver pagadas* se marcan las pagadas en Tango de los últimos 12 meses. No suman y cortan la estimación.
- **Deshacer** es baja lógica: la cuota vuelve a *Sin imputar* y lo proyectado vuelve. Una factura marcada que ya no está pendiente se toma como pagada, con aviso.
- No se marca una imputada en Alquileres (primero se deshace), ni una excluida (primero se vuelve a incluir).
- Avisos informativos: las **excluidas como pago de contrato** (para reclasificarlas como llave) y las pendientes de **cualquier proveedor** que siguen en Cuentas a Pagar Locales con leyenda «LLAVE», «CUOTA INICIAL», «SERV COMERCIALIZACION» o «PRENDA».

---

## 5. La pestaña

`Class/Llaves.php` (arma, sin reglas propias; nunca lanza), `Controller/LlavesController.php`, `Tabs/llaves_renov.php`, `Js/Llaves.js`, `Css/Llaves.css`. Un pedido (`getProyeccion`) para las tres sub-pestañas.

- **Proyección**: operador → local → *Llave*, *Otros gastos*, *Obra de imagen*; aparte *Aperturas* y *Compromisos a mano*. Las tres vistas, columnas fijas, totales arriba del eje, buscador, abrir todo y exportar con todo abierto. **Lo estimado va en itálica y atenuado**, con el chip del origen (*mixto* si una celda junta varios) y la cuenta en el tooltip. `Llaves::armarPestana()` sólo agrupa la lista de pagos con `EjeVista`.
- **Contratos**: lo que era la hoja *Detalles de vencimientos* — vigente (desde, hasta, meses, llave, comisiones, FPC), renovación cargada, estado, operador, m² y segmento, con las notas de lo que corta y el botón **Ya pagada**.
- **Facturas**: las marcadas como llave, pendientes o pagadas, con sus pagos.
- **Tarjetas**: total del horizonte, renovaciones con pagos en el horizonte (cantidad e importe), estimado frente a lo que está en un contrato o una factura (y lo manual), locales sin dato.

---

## 6. El tablero

`LLAVES_RENOV` disponible, tres series de **la misma lista de pagos** (`LlavesProvider::armarSeries()`):

| Serie | Qué es |
| --- | --- |
| `REAL` | Las facturas marcadas como llave, en sus pagos |
| `PROYECTADO` | Contratos de la app, lo estimado y los compromisos a mano que ninguna factura reemplazó |
| `PAGOS` | Las dos juntas: la fila de antes del split. `componentes` impide que conviva con las partes |

Lo estimado se anota en cada celda con `detalle` (no suma). **La pestaña y el tablero suman lo mismo, columna por columna**, y lo fija una prueba con todos los orígenes adentro. Los avisos de la pestaña llegan al tablero.

**La fila nueva, por mes** (al 23/11/2026, con el código de la rama; el Flujo Neto baja lo mismo en cada mes):

| Mes | A. Apenas corridos los scripts | B. Con los sugeridos por operador | C. B + «Ya pagada» Arcos, Abasto, DOT y Alto Palermo #90 |
| --- | ---: | ---: | ---: |
| nov-26 | 0 | 0 | 0 |
| dic-26 | 0 | 35.022.127 | 0 |
| ene-27 | 0 | 24.100.000 | 0 |
| feb-27 | 0 | 0 | 0 |
| mar-27 | 0 | 25.507.856 | 25.507.856 |
| abr-27 | 0 | 9.263.457 | 9.263.457 |
| may-27 | 0 | 9.263.457 | 9.263.457 |
| jun-27 | 0 | 9.263.457 | 9.263.457 |
| jul-27 | 0 | 117.537.377 | 78.515.258 |
| ago-27 | 0 | 42.932.661 | 3.910.541 |
| sep-27 | 0 | 42.932.661 | 3.910.541 |
| oct-27 | 0 | 42.932.661 | 3.910.541 |
| **Total** | **0** | **358.755.715** | **143.545.109** |

**A**: sin % de impuestos por operador, ninguna llave se proyecta (los avisos lo dicen). **B**: % de impuestos y de otros gastos sugeridos de IRSA, Cencosud y DOT (y de impuestos de Paseo del Siglo y Caballito), escribano 0; Arcos, BA Mall, Los Gallegos, Continental Urbana, Condominio Rubinstein, Emprendimientos Terminal y Fortín Maure siguen sin % de impuestos y no proyectan. **C**: B con lo que ya se pagó en agosto marcado.

---

## 7. Avisos

Cada hecho, un aviso aparte: contrato vencido sin renovación cargada (atención); contratos que vencen en el horizonte (informativo); local sin contrato en la app; local sin razón social en Alquileres; mes sin alquiler proyectado en Alquileres; operador sin % de impuestos, sin % de otros gastos o sin escribano; local sin % de retención; meses del vigente menos de 12; local sin m² ni segmento; cortes sin confirmar; segmento sin costo en U$S; mes sin dólar futuro; cuota de contrato firmado ya pasada sin factura (mes en curso y anterior); facturas excluidas como pago de contrato; facturas con leyenda de llave en Proveedores Locales; facturas marcadas que ya no están en Tango o cuyo pendiente cambió; falta correr un script.

---

## Permisos

| Clave | Para qué |
| --- | --- |
| `cashflow.tab.llaves_renov` | Ver la pestaña (`getProyeccion` la pide) |
| `cashflow.editar.llaves_renov` | «Ya pagada» desde la solapa Contratos |
| `cashflow.editar.parametros.llaves_renov` | Todas las escrituras de Parámetros › Llaves y Renovaciones (también «Ya pagada») |
| `cashflow.editar.alquileres` (de Alquileres) | Marcar y deshacer «Es llave / renovación» en Alquileres › Facturas |

Las tres primeras van en `sql/cashflow_llaves_permisos.sql`, que **no asigna roles**. Toda escritura pasa por `AuthCashflow::usuarioDeEscritura()`; sin permiso de edición, los controles no se dibujan.

---

## Pruebas

```
php tests/run.php llaves
```

- `test_llaves_lectura.php` — los m² (null sin medidas, no 0) y que `LocalesMedidas` no escribe. Los importes de los contratos están en `test_alquileres_app.php`.
- `test_llaves_parametros.php` — los validadores (vacío no es 0, no renueva con motivo, ya pagada no futura, series), el script 66 (siembra sólo los defaults, sin bajas físicas) y el cableado de la sub-pestaña.
- `test_llaves_calculo.php` — la estimación con el % general, del operador y del local, meses y cuotas, corrida al hábil; corrimiento; llave a mano; impuestos y retención; la renovación cargada reemplaza; llave = 1; qué locales estiman; vencido al mes en curso; no renueva; sin alquiler o sin razón social, null con aviso; otros gastos con y sin escribano o %; el segmento en los bordes; obra y apertura con curva, aproximada y sin curva; compromisos sueltos y en serie; ya pagada y facturas cortan; pagos de hoy o anteriores; los sugeridos.
- `test_llaves_facturas.php` — `destino()` igual para todos (no está en Alquileres, no vuelve a Proveedores Locales), deshacer la devuelve, la grilla de Alquileres, la renovación por mes, la validación y los pagos, `paraCalculo()` y que reemplace lo proyectado de su local, concepto y renovación.
- `test_llaves_pestana.php` — la grilla (cada pago una vez en el pie), el origen y el tooltip, las tarjetas, los contratos, el menú, los permisos y el endpoint.
- `test_llaves_tablero.php` — **pestaña = tablero, columna por columna, con todos los orígenes**; REAL + PROYECTADO = PAGOS; la anotación de lo estimado; el registro; la fila agrupada contra el validador.
- Cableado en las existentes: `test_auth_cashflow.php`, `test_avisos.php`, `test_avisos_pestana.php`, `test_cargando.php`, `test_kpis_plegables.php`, `test_parametros_carga.php`, `test_providers.php`.

---

## Archivos

| Archivo | Qué es |
| --- | --- |
| `Class/AlquileresApp.php` (`contratosConImportes()`) | Los contratos de la app con sus importes (sólo lee) |
| `Class/LocalesMedidas.php` | Los m² del gestor de locales (sólo lee) |
| `Class/LlavesTango.php` | Las facturas de llave de Tango, para el % de impuestos sugerido (sólo lee) |
| `Class/LlavesParametros.php` | Parámetros › Llaves y Renovaciones: lectura, escritura y validadores |
| `Class/LlavesSugeridos.php` | Puro: los sugeridos por operador |
| `Class/LlavesCalculo.php` | Puro: la regla, cuánto y cuándo |
| `Class/LlavesFacturas.php` | Las facturas marcadas como llave: validar, leer, escribir |
| `Class/Llaves.php` | Arma Parámetros, la pestaña y la lista de pagos del tablero |
| `Class/Providers/LlavesProvider.php` | Las series `REAL`, `PROYECTADO` y `PAGOS` |
| `Class/Alquileres.php` (`alquilerMensual()`), `Class/AlquileresFacturas.php` (`A_LLAVES`) | Lo que Llaves le pide a Alquileres |
| `Controller/LlavesController.php` | Los endpoints de Parámetros y de la pestaña |
| `Tabs/llaves_renov.php`, `Js/Llaves.js`, `Css/Llaves.css` | La pestaña |
| `Tabs/parametros_llaves.php`, `Js/Parametros-Llaves.js` | La sub-pestaña de Parámetros |
| `sql/cashflow_llaves.sql`, `sql/cashflow_llaves_permisos.sql`, `sql/cashflow_llaves_facturas.sql`, `sql/cashflow_llaves_fila.sql` | Los scripts 66 a 69 |

---

## Pendientes de confirmar

- **Comisiones de Cencosud (SAUNIS, Unicenter SA).** Las factura otro proveedor, que no está dado de alta en Alquileres: una factura suya queda en Cuentas a Pagar Locales y, si el concepto también está en Llaves (contrato o estimado), se cuenta dos veces. Hoy no hay ninguna pendiente; un aviso informativo las nombra cuando aparezcan. **Falta decidir si SAUNIS se da de alta en Alquileres** para poder marcarlas como llave.
- **Los % de impuestos de los operadores sin historia en Tango** (Arcos, BA Mall, Los Gallegos, Continental Urbana, Condominio Rubinstein, Emprendimientos Terminal, Fortín Maure): sin cargarlos, su llave no se proyecta.
- **Las renovaciones ya pagadas y no cargadas** (Arcos y Abasto): hasta que administración cargue los contratos, se marcan «Ya pagada».
- **Contratos «puente» de la app** (Unicenter #74, Solar #88, Caballito #33): se toman como contratos; Solar está vencido con un puente sin llave, así que no estima llave (sí otros gastos).
- **Un contrato cargado sin anterior** (el primero de un local) no se puede marcar «Ya pagada»: su clave es el contrato que renueva, y no hay. Hoy ninguno tiene cuotas por delante.
