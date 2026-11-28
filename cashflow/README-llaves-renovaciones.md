# Módulo Llaves y Renovaciones — cuánto se paga de llave, renovación, obra y apertura, y cuándo

Pestaña **RRHH y Operativos → Llaves y Renov. Contratos** (sub-pestañas *Proyección*, *Contratos* y *Facturas*), la sub-pestaña **Parámetros › Llaves y Renovaciones**, la acción **«Es llave / renovación»** en *Alquileres › Facturas* y el proveedor `LLAVES_RENOV` del tablero, en una fila agrupada en **real** y **proyectado**.

Rama: `feature/llaves-renovaciones`. Reemplaza las hojas *LLves y Renov Contratos* y *Detalles de vencimientos* del Excel de Flujo de Fondos de Tesorería.

---

## La idea en una línea

**La llave de cada renovación de contrato sale del contrato nuevo cargado en la app de alquileres; si todavía no está, se estima con el criterio de Costo de Ocupación —% × (1 + 2 + 8 del mes, como lo proyecta Alquileres) × meses— y se paga en cuotas. Las comisiones van en las mismas cuotas que la llave; el FPC y el escribano, en la firma; las obras y aperturas en dólares, lo pactado tal cual, y una factura marcada como llave reemplaza todo lo proyectado de su local, concepto y renovación.**

```
App de alquileres (central, SÓLO SE LEE)      Alquileres (cashflow)            Parámetros › Llaves (cashflow)
  RO_T_CONTRATOS_ALQUILERES                     alquilerMensual(): 1+2+8 neto    generales (25 %, 4 cuotas, día 15,
    vigencia, llave (4), comisiones (5),        operador = razón principal         cortes por m², U$S por segmento)
    FPC (18)                                    % retención del local            por operador (% llave, % comisiones,
Gestor de locales (XL-APPS, SÓLO SE LEE)        Facturas: «Es llave»               % FPC, escribano, % impuestos, cuotas,
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
| 73 | `sql/cashflow_llaves_ajustes.sql` | `central` | Los ajustes del testeo: `MONTO` de «Ya pagada» opcional (`CHECK (MONTO IS NULL OR MONTO > 0)`); `PCT_COMISIONES` y `PCT_FPC` por operador, vacíos (**no migra `PCT_OTROS`**: queda sin usarse); el `CHECK` de `CONCEPTO` de las facturas con `COMISIONES` y `FPC` (sigue admitiendo `OTROS`). Al pie lista las marcas `OTROS` vigentes y los operadores con `PCT_OTROS` cargado. No borra nada; reejecutable | Los % de comisiones y de FPC se ven deshabilitados y nombran el script, y las comisiones y el FPC estimados no se proyectan (avisa); «Ya pagada» desde Contratos da un error que nombra el script (en Parámetros se puede marcar con monto); Comisiones y FPC no aparecen al marcar una factura. Lo demás anda |

El 66 y el 68 van después de los de Alquileres (51 y 53); el 69, después de `sql/cashflow_estructura_grupos.sql`. El 67 es independiente. El 73, después del 66 y el 68 (si falta una de sus tablas, saltea esa parte y lo dice). **Orden recomendado: 66 → 67 → 68 → 69 → 73.**

**El tablero se mueve con el código, no con los scripts**: `LLAVES_RENOV` pasa a disponible y la fila 17 (activa, en cero hasta hoy) empieza a sumar. Sin el 66 suma cero; con el 66 y sin % de impuestos cargado por operador, la llave tampoco se proyecta (ver *El tablero*, abajo).

### Después de correrlos

1. Dar `cashflow.tab.llaves_renov` y, a quien corresponda, `cashflow.editar.parametros.llaves_renov` (Parámetros) y `cashflow.editar.llaves_renov` («Ya pagada» desde la solapa Contratos) desde Gestión de Usuarios. Marcar una factura como llave pide `cashflow.editar.alquileres`, como excluirla.
2. En *Parámetros › Llaves y Renovaciones*: confirmar los **cortes de segmento** sugeridos, cargar los **costos en U$S**, y por operador el **% de impuestos**, el **% de comisiones** y el **% de FPC** (con «Usar» al lado de cada sugerido) y el **escribano** (0 si no hay).
4. Después del **73**: volver a cargar, separados, el % de comisiones y el de FPC de los operadores que tenían **% de otros gastos** (al 27/11/2026: Emprendimientos Terminal 2 %, BA Mall 8 %, Condominio Rubinstein 0 %, Los Gallegos 6,72 %, Fortín Maure 8 %, IRSA 43,09 %, Paseo del Siglo 4,15 %, Urbana Centros Comerciales 8,36 %, Cencosud 39,48 %). Mientras no se carguen, sus comisiones y su FPC estimados no se proyectan. No había facturas marcadas con «Otros gastos».
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

| Campo | Vacío | Sugerido (chip con «Usar») |
| --- | --- | --- |
| % de llave | Hereda: «usa 25 % (general)» | Las renovaciones de la app de ese operador (`LlavesSugeridos::porOperador()`) |
| % de comisiones | Sin dato: avisa y las comisiones estimadas no se proyectan | Ídem, comisiones ÷ llave, ponderado por la llave |
| % de FPC | Sin dato: avisa y el FPC estimado no se proyecta | Ídem, FPC ÷ llave, ponderado por la llave |
| Escribano y otros ($ por renovación) | Sin dato: avisa; no se suma | — |
| **% de impuestos** sobre llave, comisiones y FPC | **Sin dato: avisa y la llave, las comisiones y el FPC de ese operador no se proyectan** | La relación factura / contrato de Tango (`LlavesSugeridos::impuestos()`): IRSA 31 %, DOT 24,5 %, Paseo del Siglo y Caballito 21 %, Cencosud 0 % (la factura «LLAVE L2» es llave + comisiones, sin impuestos) |
| Cuotas | Hereda: «usa 4 (general)» | — |
| Corrimiento (meses respecto del inicio; negativo = antes) | Hereda: «usa 0 (por defecto)» | — |

**Lo sugerido no se confunde con lo grabado.** Ningún número va adentro de un campo vacío (un placeholder en gris parecía un dato cargado: un operador quedó sin % de impuestos creyendo que lo tenía). Lo grabado se ve como dato normal, con quién y cuándo en *Último cambio*. Un campo vacío dice debajo qué pasa: lo que **hereda** y de dónde (`LlavesCalculo::queSeUsa()`, la misma regla que usa el cálculo) o **«sin dato»** con el borde punteado, si frena o recorta la proyección. El **sugerido** va al lado, en un chip con su tooltip y el botón **«Usar»**, que lo graba con auditoría como cualquier cambio. Vale también en Generales (los cortes, con sugerido; los costos en U$S vacíos, sin dato) y en Por local.

**El % de otros gastos ya no se usa** (script 73): `PCT_OTROS` queda en la base y el código no lo lee ni lo escribe; al guardar un operador, la fila nueva lo deja vacío y el valor anterior queda en el historial.

**Las facturas de Tango dicen cuánto se facturó, no cómo se paga.** IRSA factura la «cuota inicial» una vez y la paga en cuotas con echeqs (Avellaneda L6 $ 98,25 M: 19,65 + 19,65 + 9,825 M). Por eso de Tango sólo sale el % de impuestos sugerido; las facturas se ven como referencia en el tooltip y nunca se sugieren cuotas con ellas.

### 2.c Por local

Sólo los que tienen un contrato que vence dentro del horizonte o ya vencido, sin renovación cargada. Cuelga del **contrato que vence** (`ID_CONTRATO`), así que sigue ahí cuando administración carga el contrato nuevo. **Lo que define cuánto** —**No renueva**, **llave a mano**, **% propio** y **meses**— deja de aplicarse en ese momento: manda el importe real del contrato (con la marca de llave 1, sus meses y el % del operador o el general). **Lo que define cómo se paga** —**cuotas** y **mes de la primera cuota**— sigue valiendo, también sobre el contrato cargado: es una forma de pago acordada, no una estimación. **La tabla muestra también las renovaciones cargadas** que siguen vigentes o son futuras y tienen al menos una cuota desde el mes pasado: las dos columnas de cómo se paga se editan, y las cuatro de cuánto se ven deshabilitadas, con el tooltip *manda el contrato*.

**Sólo lo vigente.** Una cargada cuyo contrato nuevo ya terminó, o con todas sus cuotas antes del mes pasado, no se muestra (Alto Palermo #85, que renovó al #3 y terminó el 15/10/2026). El criterio de «vieja» es el de la proyección: `LlavesCalculo::fechasCuotas()` y `esVieja()`, escritos una sola vez. Sus parámetros siguen en la base, con su historial. **Parámetros no lee el calendario**: ahí el corrimiento al hábil es de lunes a viernes, y sólo cambiaría algo si un feriado cae en el borde del mes de la última cuota.

La columna **Contrato** muestra el que rige: en una cargada, el contrato nuevo (#, desde, hasta, llave) y debajo, en chico, «renueva al #N»; en una estimada, el vigente. Un campo vacío dice debajo qué usa: «usa 25 % (operador)», «usa 36 (contrato #22)», «usa 4 (general)», «usa ene-2027».

**No renueva** (con motivo obligatorio), **llave a mano** (reemplaza el cálculo; origen *Manual*), **% propio**, **meses del contrato nuevo** (por defecto, los del último contrato con llave), **cuotas** y **mes de la primera cuota**.

### 2.d Ya pagadas

La llave, los otros gastos (comisiones, FPC y escribano) **o los dos** de una renovación que **ya salieron de caja**: fecha, comprobante y observación en común, y **un monto opcional** por concepto (script 73: es informativo, no suma ni resta; si se carga, mayor que 0). Desde la solapa *Contratos* no se piden importes. Sin monto, las notas y la tabla lo omiten: «Ya pagada (llave) el 31/08/2026». **El formulario trae los dos conceptos tildados**: lo normal es marcar la renovación entera, y marcar sólo la llave deja los otros gastos proyectados sin que nadie lo note (Abasto: la cuota inicial y la publicidad de lanzamiento se facturaron juntas en agosto). Un concepto que ya estaba marcado no se toca y se informa. Corta cada concepto marcado en esa renovación, cargada o estimada, y **sigue valiendo cuando se carga el contrato nuevo**. No suma. Se marca desde Parámetros o desde la solapa *Contratos* (con el permiso de la pestaña).

### 2.e Obras, aperturas y compromisos

- **Obra de imagen**: local, mes, segmento (vacío = por m²), costo propio en U$S opcional, observación. Una por local y mes.
- **Apertura**: nombre, mes, segmento obligatorio, costo propio opcional; se vincula a un número de sucursal cuando exista.
- **Compromiso a mano**: operador (razón de Alquileres o proveedor del maestro de Proveedores Locales), local opcional, concepto, fecha, monto, forma de pago informativa, observación; **en serie**, N cuotas mensuales del mismo importe (el último día del mes si el día no existe). Se da de baja la cuota o la serie entera.

---

## 3. Cómo se proyecta

`Class/LlavesCalculo.php` es **la** regla, pura: hoy, contratos, alquiler, m², curva, parámetros y facturas entran ya leídos.

### 3.a Renovación cargada en la app

- **Llave**: su importe en N cuotas mensuales iguales y consecutivas (local > operador > general), la primera el día de inicio del contrato (+ corrimiento del operador), cada una corrida al hábil siguiente.
- **Comisiones**: en **las mismas N cuotas y fechas que la llave**, repartidas con `cuotas()` (al centavo) y cada cuota con impuestos y retención (`importes()`). Si la llave no se proyecta porque la cortó una «Ya pagada» o una factura, las comisiones igual van en las N fechas que tendría. Tooltip: *«Comisiones $ 20.000.000 del contrato #95, cuota 2 de 4. Neto … + impuestos … − retención … = a pagar …»*.
- **FPC**: un pago, el día de la primera cuota (la firma), con impuestos y retención.
- Vale para **cualquier contrato con cuotas por delante**, también el vigente si empezó hace poco (DOT #91).
- **Llave = 1**: se estima (3.b) con los meses de ese contrato y el % del operador o el general —no el % propio del local, que es de la estimación—; nunca se pagan $ 1.
- **De los parámetros por local valen las cuotas y el mes de la primera cuota**, también sobre el contrato cargado; el % propio, los meses y la llave a mano, no (ver 2.c).

### 3.b Sin la renovación cargada

```
llave estimada = % × (1 + 2 + 8 del mes de la primera cuota, como lo proyecta Alquileres) × meses
```

- Sólo si **el contrato de llave tiene llave** (> 0, la marca incluida): el criterio de Costo de Ocupación (`verificarValorLlaveNegocio()`). El contrato de llave (`LlavesCalculo::contratoDeLlave()`) es el vigente o, si el vigente es un **puente** —sin llave y de menos de 12 meses—, el último contrato anterior que no lo sea. **Un puente no hace que el local deje de pagar llave**: Solar, cuyo vigente es el puente #88 (tres meses, sin llave), estima con el #8 (llave $ 6,3 M, 36 meses). Un contrato largo sin llave sí corta: Gurruchaga y MDP Aldrey (#75, 48 meses, después de uno con llave de 2019) no estiman llave, pero sí otros gastos.
- El alquiler es el **neto** que proyecta Alquileres (`Alquileres::alquilerMensual()`, antes de las facturas). Si Alquileres no tiene ese mes para el local, **no proyecta** y avisa.
- Meses: los del local; si no, los del **contrato de llave** (el vigente, o el anterior al puente). **Un contrato corto con llave no se avisa**: es normal (Alto Palermo renueva cada 5 o 6 meses). El aviso de "menos de 12 meses" que hubo se borró.
- Primera cuota: el mes del día siguiente al fin del vigente + corrimiento, o el del local. **Vencido sin renovar**: la primera cuota va al primer hábil después de hoy, con aviso de atención.

### 3.c Comisiones, FPC y escribano estimados

- **Comisiones** = `% de comisiones del operador × llave estimada`, en las cuotas de la llave.
- **FPC** = `% de FPC del operador × llave estimada`, un pago en la firma.
- **Escribano y otros**: el importe del operador por renovación, un pago en la firma; es un importe final, sin impuestos ni retención.

Cada uno es un concepto aparte, con su chip y su tooltip. Sin uno de los %, ese concepto no se proyecta y el aviso lo nombra. Hasta el script 73 había un solo % de otros gastos; ya no se usa.

### 3.d Impuestos y retención

`neto × (1 + % de impuestos del operador) − neto × % de retención del local`, con `AlquileresPago::importes()` —la misma regla de redondeo que Alquileres— pasándole el % de impuestos como la única alícuota. Por qué no un helper propio: así la suma, la retención sobre el neto y el redondeo a centavos están escritos una sola vez, sin agregarle a `importes()` un caso que Alquileres no tiene. Sin % de impuestos o sin % de retención, la llave, las comisiones y el FPC de ese local **no se proyectan**, y el aviso dice el neto que falta. **El escribano sí se proyecta**: es un importe final, sin impuestos ni retención, así que no le falta nada. Los dos avisos lo dicen.

### 3.e Obras y aperturas

Costo en U$S (el propio o el del segmento) × `DolarFuturo::resolver()` de su fecha: el día de pago general del mes, corrido al hábil. Sin curva para ese mes, el más cercano marcado *aproximado* en el tooltip; sin curva, null con aviso. **El segmento** (`LlavesCalculo::segmento()`): hasta el corte de chico, chico; hasta el de mediano, mediano; más, grande. Los bordes son del segmento de abajo. Sin m² y sin segmento a mano, no proyecta.

### 3.f Compromisos a mano

Su fecha y su monto, tal cual (origen *Manual*).

### 3.g Lo que corta

- **No renueva**: nada de esa renovación.
- **Ya pagada**: la de la llave, la llave; la de otros gastos, las comisiones, el FPC y el escribano.
- **Una factura marcada como llave**: todo lo proyectado de su concepto en esa renovación (parte 4). Una marcada con el concepto viejo «Otros gastos» corta comisiones, FPC y escribano, y avisa para que se rehaga.
- **Los pagos de hoy o anteriores no se proyectan.** Una cuota de un contrato firmado que ya pasó, del mes en curso o el anterior, sin factura ni marca, se avisa.

Cada pago lleva su **origen** (*Contrato cargado*, *Estimado*, *Manual*, *Factura*) y la cuenta en el tooltip: *«25% (general) × $ 18.927.137 (alquiler + complementario + VMA de enero de 2027…) × 41 meses = $ 194.003.154, cuota 2 de 4. Neto … + 21% de impuestos … − 1% de retención … = a pagar …»*.

---

## 4. Las facturas reales: «Es llave / renovación»

`Class/LlavesFacturas.php` (script 68), en *Alquileres › Facturas*, junto a *Excluir*, por cuota o las tildadas.

- Pide el **local**, el **concepto** (Llave / Comisiones / FPC / Obra) y el **mes**. Sin el script 73, sólo Llave y Obra. **Las marcas viejas con «Otros gastos» no se migran**: se ven como «Otros gastos (marca anterior)», siguen reemplazando comisiones, FPC y escribano de su renovación, y un aviso pide deshacerlas y volver a marcarlas. La llave, las comisiones y el FPC se imputan a **la renovación que empieza más cerca de ese mes** (`renovacionDeMes()`): la factura de agosto de Arcos es de la renovación de enero, no del contrato de 2023. La obra, al local y mes.
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

- **Proyección**: operador → local → *Llave*, *Comisiones*, *FPC*, *Escribano y otros*, *Obra de imagen* (y *Otros gastos (marca anterior)* sólo con una factura vieja); aparte *Aperturas* y *Compromisos a mano*. Las tres vistas, la columna «Operador · local · concepto» **fija** al scrollear a lo ancho (`.tabla-temporal` + `col-fija-1`, como Alquileres), totales arriba del eje, buscador, abrir todo y exportar con todo abierto. **Lo estimado va en itálica y atenuado**, con el chip del origen (*mixto* si una celda junta varios) y la cuenta en el tooltip. `Llaves::armarPestana()` sólo agrupa la lista de pagos con `EjeVista`.
- **Contratos**: lo que era la hoja *Detalles de vencimientos* — vigente (desde, hasta, meses, llave, comisiones, FPC), renovación cargada, estado, operador, m² y segmento, con las notas de lo que corta y el botón **Ya pagada**.
- **Facturas**: las marcadas como llave, pendientes o pagadas, con sus pagos.
- **Tarjetas**: total del horizonte, renovaciones con pagos en el horizonte (cantidad e importe, **los dos sobre el mismo universo**: los pagos de llave, comisiones, FPC y escribano de cualquier origen, facturas incluidas; una factura cuenta en la renovación a la que se imputó, así que no suma una renovación aparte), estimado frente a lo que está en un contrato o una factura (y lo manual), locales sin dato.

---

## 6. El tablero

`LLAVES_RENOV` disponible, tres series de **la misma lista de pagos** (`LlavesProvider::armarSeries()`):

| Serie | Qué es |
| --- | --- |
| `REAL` | Las facturas marcadas como llave, en sus pagos |
| `PROYECTADO` | Contratos de la app, lo estimado y los compromisos a mano que ninguna factura reemplazó |
| `PAGOS` | Las dos juntas: la fila de antes del split. `componentes` impide que conviva con las partes |

Lo estimado se anota en cada celda con `detalle` (no suma), nombrando local y concepto (*Comisiones*, *FPC*, *Escribano y otros*). **La pestaña y el tablero suman lo mismo, columna por columna**, y lo fija una prueba con todos los orígenes y los conceptos nuevos adentro (comisiones en cuotas, FPC, facturas de comisiones y de FPC). La estructura no cambió con el script 73: la fila sigue abriéndose en *Real* y *Proyectado*.

**Con el script 73 sin correr y el código de `feature/llaves-ajustes-testeo`** (al 27/11/2026), la fila baja **$ 181,2 M** en el horizonte: las comisiones y el FPC estimados no se proyectan hasta cargar los dos % nuevos, y las comisiones de los contratos cargados pasan de la firma a las cuotas. Por mes: nov-26 −1,68 M · dic-26 −2,96 M · ene-27 −6,69 M · feb-27 +0,99 M · mar-27 −162,51 M · jul-27 −8,34 M; el resto, igual.

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

Cada hecho, un aviso aparte: contrato vencido sin renovación cargada (atención); contratos que vencen en el horizonte (informativo); local sin contrato en la app; local sin razón social en Alquileres; mes sin alquiler proyectado en Alquileres; operador sin % de impuestos (no se proyectan la llave, las comisiones ni el FPC; el escribano, sí), sin % de comisiones, sin % de FPC (cada uno aparte, y sin el 73 lo nombra) o sin escribano; local sin % de retención (ídem); facturas marcadas con el concepto viejo «Otros gastos»; cuota de contrato firmado ya pasada sin factura, mes en curso y anterior (también las de comisiones); local sin m² ni segmento; cortes sin confirmar; segmento sin costo en U$S; mes sin dólar futuro; facturas excluidas como pago de contrato; facturas con leyenda de llave en Proveedores Locales; facturas marcadas que ya no están en Tango o cuyo pendiente cambió; falta correr un script.

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
- `test_llaves_parametros.php` — los validadores (vacío no es 0, no renueva con motivo, ya pagada no futura y sin monto, series, % de comisiones y de FPC), los scripts 66 y 73, el cableado de la sub-pestaña, Por local sólo con lo vigente, lo que se hereda (`queSeUsa()`) y que ningún campo lleve un número como placeholder ni «Usar» deje de grabar.
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
| `sql/cashflow_llaves_ajustes.sql` | El script 73: «Ya pagada» sin monto, % de comisiones y de FPC, facturas de comisiones y FPC |

---

## Pendientes de confirmar

- **Comisiones de Cencosud (SAUNIS, Unicenter SA).** Las factura otro proveedor, que no está dado de alta en Alquileres: una factura suya queda en Cuentas a Pagar Locales y, si el concepto también está en Llaves (contrato o estimado), se cuenta dos veces. Hoy no hay ninguna pendiente; un aviso informativo las nombra cuando aparezcan. **Falta decidir si SAUNIS se da de alta en Alquileres** para poder marcarlas como llave.
- **Los % de impuestos de los operadores sin historia en Tango** (Arcos, BA Mall, Los Gallegos, Continental Urbana, Condominio Rubinstein, Emprendimientos Terminal, Fortín Maure): sin cargarlos, su llave no se proyecta.
- **Las renovaciones ya pagadas y no cargadas** (Arcos y Abasto): hasta que administración cargue los contratos, se marcan «Ya pagada».
- **El corte de 12 meses de un puente** (`LlavesCalculo::MESES_PUENTE`). Hoy cubre Unicenter #74 (1 mes), Solar #88 (3) y Caballito #33 (2); Alto Palermo #85 y #90 y Parque Brown #84 son cortos pero tienen llave, así que no son puentes. Un contrato sin llave de 12 meses o más corta la llave.
- **Un contrato cargado sin anterior** (el primero de un local) no se puede marcar «Ya pagada»: su clave es el contrato que renueva, y no hay. Hoy ninguno tiene cuotas por delante.
