# Módulo Cob. Electrónicos — acreditaciones de las procesadoras de pago

Reemplaza el placeholder de la pestaña **Cob. Electrónicos** y alimenta la fila del tablero que hasta ahora rendía cero: *Cobranzas Pagos Electrónicos*, en la sección **Disponibilidades**.

Rama: `feature/cob-electronicos`

---

## La idea en una línea

**El neto no se tipea: se calcula, se persiste, y la tasa con la que se calculó se guarda al lado.**

```
Movimiento (procesadora, importe bruto, fecha de acreditacion)
        │
        ├─ tasa de retencion vigente de esa procesadora A ESA FECHA
        ▼
importe neto = bruto * (1 - tasa)      ← se PERSISTE, junto con la tasa aplicada
        │
        ▼
   serie COBRANZA (suma de NETOS por fecha de acreditacion)
        │
   CobElectronicosProvider → Cashflow
        │
   fila "Cobranzas Pagos Electronicos" (Disponibilidades)
```

Son las acreditaciones que las procesadoras (Payway, Mercado Pago) van a depositar en nuestro banco: el importe bruto que informan, la fecha en que lo van a acreditar, y el neto que efectivamente entra después de las retenciones impositivas de la procesadora.

---

## Ejecución de los scripts

Contra `central`, en este orden:

```sql
-- 1. sql/cashflow_cob_electronicos.sql            las 3 tablas + semillas
-- 2. sql/cashflow_cob_electronicos_migracion.sql  los movimientos futuros del Excel
```

El primero crea las tres tablas y siembra Payway y Mercado Pago con IIBB 0,025 y SICREB 0,006. **Es reejecutable**: las tablas se crean sólo si no existen y las semillas entran por `MERGE WHEN NOT MATCHED`, así que una segunda corrida no duplica nada ni pisa un porcentaje ya editado. Cierra con un cuadro que dice, por procesadora, cuántos conceptos tiene vigentes y cuánto suman.

**No crea ningún parámetro clave/valor.** Este módulo no tiene ninguno: las alícuotas y las procesadoras son datos de negocio y viven en sus propias tablas. Inventar un parámetro que nadie pidió sólo agrega un lugar más donde buscar.

Si el script **no se corrió**, la pantalla no falla: muestra un aviso con el nombre del script y el tablero deja la fila en cero, igual que hace hoy.

No hay que tocar la estructura del tablero ni el motor: la fila ya la sembró `sql/cashflow_estructura.sql` y `sql/cashflow_estructura_disponibilidades.sql` la dejó en Disponibilidades, con orden 30 y `COMPUTA = 1`. Lo único que cambió del cableado existente es el flag `disponible` del registro.

---

## El bug del Excel, y cómo se arregla

En la hoja original hay **dos** problemas, y son dos mitades del mismo:

1. **`D8:D43` tienen `*0.969` escrito a mano.** Sólo `D46:D55` —las filas vacías— usan `$D$3`, que es donde vive la fórmula `=1-0.025-0.006`. Cambiar `D3` hoy no recalcula ni un movimiento: el 0,969 quedó congelado en cada celda el día que se pegó.
2. **`D6`, `D7` y `D24` no tienen fórmula.** Nunca calcularon neto, con 1.648.264,10 / 1.144.017,00 / 43.150.368,26 de bruto respectivamente. Tres movimientos que informan bruto y no informan nada de lo que va a entrar al banco.

La app arregla las dos mitades, y ninguna de las dos alcanza sola:

| Qué se hizo | Por qué no alcanzaba la otra mitad |
| --- | --- |
| **El neto lo calcula el servidor al guardar** y queda persistido junto con `TASA_APLICADA`. Nunca se tipea ni se acepta del navegador | Sin esto, la fórmula sigue estando pero cada fila puede tener el número que quiera. Aceptar el neto del cliente es la versión informática del `*0.969` pegado a mano |
| **Un movimiento no puede quedar sin neto.** `TASA_APLICADA` e `IMPORTE_NETO` son `NOT NULL`, y si la procesadora no tiene alícuotas vigentes a esa fecha **el alta se rechaza con el motivo** | Sin esto, el caso `D6`/`D7`/`D24` se puede volver a producir: se carga el bruto, no hay con qué calcular, y la fila queda muda |

**Editar un porcentaje no reescribe lo ya informado.** Una alícuota no se edita con un `UPDATE` sobre la fila vieja: se inserta una **vigencia nueva** con su `VIGENCIA_DESDE`. La vigencia anterior queda, y es lo que explica por qué un movimiento de la semana pasada tiene otra tasa. Sin ella, ese neto parecería un error de cálculo.

La tasa de un movimiento se resuelve **contra su fecha de acreditación**, no contra la fecha de carga: es la tasa que rige el día en que la plata entra.

### El recálculo de los pendientes es automático

Decisión del negocio, tomada explícitamente: al guardar (o dar de baja) una alícuota, el servidor recalcula el neto de los movimientos **pendientes** de esa procesadora —los que tienen fecha de acreditación de hoy en adelante— y el mensaje dice **cuántos cambiaron y por cuánta plata**, con el detalle de tasa vieja → tasa nueva y neto viejo → neto nuevo.

> Lo propuesto era un botón explícito con vista previa, y la objeción sigue en pie: recalcular al editar un parámetro es la mitad del problema del Excel corriendo al revés —ahí un cambio de `D3` no llegaba a ningún movimiento, acá llega a todos los pendientes de una sola vez—. Se implementó automático porque así se pidió. Lo que compensa el riesgo es que **el recálculo nunca es silencioso**: el plan de cambios se calcula antes de escribir (`CobElectronicos::planRecalculo()`, que es un helper puro y devuelve un plan, no una escritura) y se informa completo en la respuesta. Si algún día se quiere volver al botón con vista previa, el plan ya está separado de la escritura y el cambio es sólo de dónde se lo llama.

**Los movimientos ya acreditados no se tocan nunca.** Su plata ya entró con la tasa con la que entró, y recalcularlos sería reescribir la historia. Es el mismo motivo por el que la tasa se persiste en la fila.

El recálculo escribe **en una transacción**: a mitad de camino quedaría una parte de los pendientes con la tasa nueva y otra con la vieja, sin ninguna forma de saber cuál es cuál.

---

## Un movimiento con fecha anterior al eje **no** abre el horizonte

**Es lo inverso a lo que hace Saldos, y es a propósito.**

`Saldos::destinoEnEje()` reubica un saldo viejo en la primera columna del eje. Este módulo **no**:

| | Qué describe el dato | Qué se hace con una fecha pasada |
| --- | --- | --- |
| **Saldos** | Plata que **existe ahora**: un saldo bancario, el efectivo del cajón de un local | Se reubica en la apertura del horizonte. Una fecha pasada significa *"esto ya es cierto hoy"* |
| **Cob. Electrónicos** | Un **movimiento ya ocurrido**: esa acreditación ya entró a la cuenta | Queda **fuera del eje**, en `fuera_horizonte`, con aviso |

El motivo es concreto: una acreditación con fecha pasada **ya la informa el saldo bancario de la pestaña Saldos**. Reubicarla en la apertura del horizonte la contaría **dos veces** — una como saldo y otra como cobranza futura.

Nunca se descarta en silencio: el importe va a `fuera_horizonte`, el aviso dice el total y la fecha más vieja, y **la fila se ve igual en la pantalla**, en su lugar, marcada y con el motivo. Un total de pantalla más grande que el del tablero tiene que tener explicación en la misma pantalla.

Una fecha **posterior** al eje también queda fuera, con su propio aviso: ésa es una fecha que el horizonte no cubre.

> **El corte por fecha es explícito y no se delega a `Horizonte::acumular()`.** Una fecha del mes en curso anterior a hoy —un 1/9 con el eje arrancando el 6/9— caería en la columna del mes `2026-09`, que existe en el eje pero que el tablero **ni siquiera incluye en el arrastre** (`Horizonte::secuencia()` la deja afuera porque no representa ningún día futuro). El importe quedaría en una columna que nadie suma. Con el corte por fecha en `CobElectronicos::ubicacionEnEje()`, el criterio no depende de cómo quede armado el eje. Tiene prueba propia.

---

## Esta fila no se cruza con Ventas

**Decisión del negocio, ya tomada:** la fila *Cobranzas Pagos Electrónicos* **no se solapa ni se ajusta contra *Cobros s/ ventas estimadas*** de Ventas. No hay ninguna deducción, prorrateo ni exclusión cruzada entre las dos filas, y **no está pendiente**: es como se decidió medirlo.

Queda dicho acá con esas palabras para que no aparezca como "bug" más adelante. Es el mismo tipo de invariante que el de `COBROS_VENTAS` y `COBRANZAS_FR`, que tampoco se pisan (ver `README-cashflow.md`), sólo que acá el criterio no se deduce del dato sino que es una decisión.

---

## De dónde sale el dato

**Carga manual en pantalla ahora; importación del archivo de la procesadora después.**

Se aplicó el mismo criterio que Saldos con la API de Interbanking: **las columnas de la importación se crean desde el día uno y quedan en `NULL`**, para que enchufarla no obligue a migrar datos. El formulario manual se mantiene siempre, como respaldo.

| Columna | Hoy | Después |
| --- | --- | --- |
| `ORIGEN_DATO` | `'MANUAL'` | `'ARCHIVO'` / `'API'` |
| `ID_EXTERNO` | `NULL` | Identificador de la liquidación en la procesadora |
| `ARCHIVO_ORIGEN` | `NULL` | Nombre del archivo importado |

**`ID_EXTERNO` es la clave con la que la importación va a reconocer un movimiento ya cargado a mano.** Va con índice único **filtrado** por procesadora (`WHERE ID_EXTERNO IS NOT NULL`), igual que el `CBU` de Saldos: hoy está en `NULL` en todas las filas, y un `UNIQUE` común de SQL Server admite un solo `NULL`.

---

## Modelo de datos

Tres tablas, prefijo `RO_T_CASHFLOW_COBEL_`.

| Tabla | Qué guarda |
| --- | --- |
| `RO_T_CASHFLOW_COBEL_PROCESADORA` | Quién nos deposita (parámetro) |
| `RO_T_CASHFLOW_COBEL_ALICUOTA` | Las retenciones por procesadora y **por vigencia** (parámetro + histórico) |
| `RO_T_CASHFLOW_COBEL_MOVIMIENTO` | Las acreditaciones, con la tasa y el neto persistidos |

### Por qué las alícuotas son un histórico y no un parámetro de una fila

Porque es lo que permite editar un porcentaje sin cambiar retroactivamente el neto de todo lo ya informado. `VIGENCIA_DESDE` no es *"por si acaso"*: es la diferencia entre corregir un dato y reescribir la historia.

**`CONCEPTO` no es un enum cerrado en código.** Hoy son `IIBB` y `SICREB` —las dos que estaban escondidas en la celda `D3`—, pero mañana puede aparecer una tercera retención, y agregarla tiene que ser cargar una fila y no tocar un `CHECK` ni un archivo PHP. La fórmula suma lo que haya.

**No hay `UNIQUE (procesadora, concepto, vigencia)` a propósito.** Nada impide corregir dos veces el mismo porcentaje el mismo día —es justo lo que pasa cuando alguien se equivoca al tipearlo—, y con un `UNIQUE` la segunda corrección fallaría con un error de índice. El desempate entre dos vigencias de la misma fecha es **por `ID`**, que es un `IDENTITY`: gana siempre la insertada después. Es la misma regla que `Saldos::ultimaCarga()`, y está probada, incluso con el orden de la lista invertido.

### Qué no va al modelo

Las columnas `E` (copia de `C`) y `F:AK` del Excel —la matriz de vencimientos diaria y mensual— **no se migran**: son la agrupación contra el eje temporal y las deriva el proveedor. Además ese bloque está roto: `AA5` es `#¡REF!`, `AB6:AB43` comparan dos veces contra `$AB$2` en lugar de `$AB$3`, y las columnas mensuales devuelven `FALSO` por un `IF` sin rama else. Son tres errores que desaparecen **por construcción** al agrupar sobre el dato en lugar de sobre una matriz de fórmulas, no algo a replicar.

---

## Las validaciones, y qué evita cada una

| Regla | Qué pasaría sin ella |
| --- | --- |
| `IMPORTE_BRUTO > 0` | Una acreditación de cero o negativa restaría plata del tablero |
| `FECHA_ACREDITACION` obligatoria | El movimiento no se podría ubicar en ninguna columna |
| **Σ alícuotas vigentes < 1**, validado **al guardar la alícuota** | El neto saldría cero o negativo: una **cobranza** restaría plata. Se valida cuando alguien lo escribe, no cuando ya hay movimientos mal calculados |
| **No se puede dar de alta un movimiento de una procesadora sin alícuotas vigentes** a la fecha de acreditación | Es exactamente la causa de que `D6`, `D7` y `D24` estén vacías en el Excel |
| `RAZON_SOCIAL` única, normalizada (trim + case-insensitive) | *"payway"* y *"Payway"* serían dos procesadoras con dos juegos de alícuotas, y los movimientos se repartirían entre las dos |
| Sin bajas físicas: `ACTIVO = 0` | — |

**Dos movimientos de la misma procesadora y la misma fecha se avisan, no se bloquean.** Puede haber dos liquidaciones el mismo día, así que no hay `UNIQUE (procesadora, fecha)`. El aviso alcanza para detectar el pegado doble, que es el error real que se quiere atrapar, y lo dice sin tratarlo como error: *"puede ser correcto, pero también es lo que se ve cuando una carga quedó pegada dos veces"*.

**La suma se valida sobre el estado resultante**, no sobre lo que manda el cliente: las otras vigentes más la nueva. Es el mismo criterio que usa `ParametrosController` con el mix de cobro y el validador de la estructura del tablero.

---

## La pestaña

Una sola pestaña, sin sub-pestañas.

| Columna | Editable | Por qué |
| --- | --- | --- |
| Procesadora | Sólo en el alta | Cambiarla después dejaría el movimiento con la tasa de otra procesadora. Si quedó mal, se da de baja y se carga de nuevo |
| Importe bruto | **sí** | Es un dato de entrada |
| Fecha de acreditación | **sí** | Es un dato de entrada. Al cambiarla, **la tasa se vuelve a resolver**: se resuelve contra esa fecha, y dejar la tasa vieja con una fecha nueva guardaría un neto que ninguna vigencia justifica |
| Tasa aplicada | **no** | Es del servidor |
| Importe neto | **no** | Es del servidor |
| Origen del dato | no | `MANUAL` hoy; `ARCHIVO`/`API` cuando entre la importación |

La tasa y el neto se muestran en cursiva y atenuados, y en la fila en edición se reemplazan por la leyenda *"la tasa y el neto se recalculan al guardar"*. Convertirlos en inputs sería ofrecer editar un número que el servidor va a descartar.

El formulario de alta dibuja una **vista previa del neto** mientras se tipea, y dice explícitamente que el definitivo lo calcula el servidor con la alícuota vigente a la fecha elegida. La previa usa la tasa vigente **a hoy**, que es la que trae el payload; si la fecha de acreditación cae en otra vigencia, el mensaje del guardado informa la que se usó de verdad.

El alta sólo ofrece las procesadoras **activas y con alícuota vigente**: son las únicas que pueden calcular un neto, y ofrecer las otras llevaría a un rechazo del servidor después de tipear todo el movimiento. El **filtro**, en cambio, ofrece todas, incluidas las inhabilitadas: puede haber movimientos de una procesadora que después se dio de baja, y no poder filtrarlos los esconde.

**Estado vacío honesto:** las tarjetas dicen `sin cargar`, no `$ 0,00`. Un cero se leería como *"no hay acreditaciones previstas"*, que no es lo mismo que *"no se cargó ninguna"*.

### El cuadro por día y por mes

Es la agrupación que consume el tablero: suma de netos por fecha de acreditación —`GROUP BY FECHA_ACREDITACION` y por año-mes—, **sin corrimiento a día hábil ni tratamiento de feriados**. La fecha que informa la procesadora es la fecha en que el dinero entra; correrla al lunes inventaría una fecha que el dato ya trae. Tiene prueba con un sábado.

Las dos agrupaciones son la misma suma vista de dos formas, así que **dan el mismo total**, y ese total es el neto y nunca el bruto. Las tres cosas están probadas juntas, porque son el invariante que habría que romper para que la pantalla y el tablero no cierren.

Los días que no entran al eje se ven **marcados**, no escondidos, con la nota de por qué.

---

## Parámetros

Sub-pestaña **Parámetros → Cob. Electrónicos**, con dos secciones.

**Procesadoras** — ABM. La columna *Tasa vigente hoy* muestra la suma con su desglose por concepto, para que ese porcentaje sea auditable de un vistazo. Una procesadora sin alícuotas vigentes **no muestra `0%`**: dice `sin alícuotas`, porque un cero se leería como *"no le retienen nada"*.

> Una procesadora nueva **entra inactiva**, y el switch de activación queda deshabilitado —con el motivo en el tooltip— hasta que tenga al menos una alícuota vigente. **Es un criterio distinto al de una cuenta de Saldos, que nace activa, y la diferencia no es una inconsistencia:** una procesadora activa sin alícuotas habilita altas de movimientos que después no pueden calcular neto, que es exactamente lo que dejó tres filas del Excel sin fórmula. Una cuenta de Saldos no rompe ningún invariante al nacer vacía, porque su saldo se muestra como `sin cargar` y no como cero.

El invariante se verifica en **los tres extremos**, porque en cualquiera de los tres se puede romper: al activar la procesadora, al dar de baja la última alícuota vigente de una procesadora activa, y al dar de alta un movimiento.

> Inhabilitar una procesadora frena las **altas**, no la corrección de un importe ya informado. Editar un movimiento suyo sigue permitido a propósito: si se exigiera la procesadora activa también para editar, un movimiento de una procesadora dada de baja quedaría sin salida —no se podría corregir, y tampoco dar de baja y volver a cargar, porque el alta sí la exige activa—. La alícuota vigente, en cambio, se exige en los dos casos: sin ella no hay neto que calcular.

**Alícuotas por procesadora** — `CONCEPTO`, `ALICUOTA`, `VIGENCIA_DESDE`. No hay inputs editables sobre las filas: se **carga una vigencia nueva** y la anterior queda a la vista, marcada como `histórica`. Es lo que explica la tasa de los movimientos de ese período.

El formulario muestra la **suma resultante** antes de guardar y **bloquea el botón si llega a 100%**, con la consecuencia dicha: *"una cobranza restaría plata del tablero"*. La validación que vale es la del servidor; el espejo en el JS existe sólo para no hacer tipear todo antes del rechazo.

El campo de concepto es libre, con los ya usados como sugerencia: el concepto no es un enum cerrado.

---

## El proveedor

`Class/Providers/CobElectronicosProvider.php`, código `COB_ELECTRONICOS`, serie `COBRANZA`, `moneda_origen = 'ARS'` y `tipo_cambio = null`: los importes ya están en pesos, así que no hay conversión que hacer ni que auditar.

**Suma netos, nunca brutos.** El bruto es lo que la procesadora informa; el neto es lo que entra a la cuenta. Un tablero que sume brutos proyecta plata que no va a existir — con los 601.968.975,12 de bruto de la hoja, unos 18,6 millones de retenciones que nunca llegan al banco.

### Nunca tumba el tablero

`calcular()` puede lanzar y `series()` lo envuelve. Además se atrapan los casos propios para poder rendir cero **con un aviso que diga qué pasó**, en lugar del mensaje genérico de la clase base:

| Caso | Qué dice el aviso |
| --- | --- |
| El script SQL no se corrió | El nombre del script que hay que correr |
| No hay ninguna procesadora cargada | Dónde cargarlas |
| Hay procesadoras pero ningún movimiento | Distingue *"no hay datos"* de *"los datos son cero"*, igual que Nacionalizaciones |
| Un movimiento cuya procesadora perdió sus alícuotas vigentes | Que **suma igual, con el neto que ya tenía guardado**, pero que no se van a poder cargar movimientos nuevos |
| Movimientos fuera del horizonte | El importe, la fecha, y **por qué** no entran |
| Todo lo cargado cae fuera del horizonte | Que la fila va en cero y que lo ya acreditado está en el saldo bancario |

Un movimiento cuya procesadora perdió sus alícuotas **sigue aportando su neto persistido**, y eso está bien: es el neto que se informó. Lo que hace falta saber es que de ahí en adelante esa procesadora no puede calcular nada nuevo — si no, el error aparece recién cuando alguien intenta cargar un movimiento.

### La costura para poder probarlo

`CobElectronicosProvider::modulo()` está separado de `calcular()` a propósito. Los casos en los que el proveedor tiene que rendir cero con un aviso **no se pueden montar contra una base real sin borrar las tablas**, así que la prueba pasa un doble por ahí y verifica el aviso sin base. Es la misma idea que el `Horizonte` inyectado de `Cashflow`: sin la costura, la parte más fácil de romper en silencio se queda sin red.

---

## Migración de los datos del Excel

`sql/cashflow_cob_electronicos_migracion.sql`, aparte del DDL.

**Sólo se migran los movimientos con `FECHA_ACREDITACION >= la fecha en que se corre el script.`** Lo anterior ya está acreditado y vive en el saldo bancario; migrarlo duplicaría plata en el tablero. Es la misma razón por la que el proveedor deja fuera del eje un movimiento con fecha pasada en lugar de reubicarlo.

El corte es `CAST(GETDATE() AS DATE)` y no una fecha escrita: así el criterio es el mismo el día que se corra, y no depende de que alguien se acuerde de actualizar una constante.

**El neto y la tasa se recalculan, no se copian.** Del Excel se toman únicamente los tres datos de entrada —procesadora, importe bruto y fecha de acreditación—, porque los valores de la hoja son justamente de donde viene el problema.

**Es reejecutable sin duplicar:** cada movimiento entra por `NOT EXISTS` sobre (procesadora, fecha, bruto), que es la clave natural de una liquidación informada. Una segunda corrida no inserta nada y tampoco pisa un movimiento editado desde la pantalla.

El script cierra con **dos cuadros**: uno resume qué se migró y qué quedó afuera con su motivo, y el otro lo detalla fila por fila con la tasa y el neto que se calcularon. Una migración que no dice qué dejó afuera es una migración que informa de menos en silencio.

En la hoja hay 38 movimientos (filas 6 a 43): 18 de Payway y 20 de Mercado Pago, 601.968.975,12 de bruto en total. Las fechas están como fechas reales de Excel, **no como serial**: no hace falta ninguna conversión de base 1900. Las filas 44 a 55 están vacías con fórmulas listas y no se migran.

> **Los tres movimientos que nunca calcularon neto** (`D6`, `D7`, `D24`) caen los tres en fechas ya pasadas, así que con este criterio quedan fuera solos. **Igual, antes de correr la migración hay que verificar con Tesorería que esos tres sean válidos y no duplicados**: es el único dato de la hoja que no se puede reconstruir solo.

### La única duplicación de la fórmula

El script de migración vuelve a escribir la resolución de la tasa en SQL (`OUTER APPLY` con `ROW_NUMBER()` por concepto), porque **corre en SSMS sin PHP**. Es la única duplicación de la fórmula en todo el módulo y está deliberadamente calcada de `CobElectronicos::tasaRetencion()`: misma regla —de cada concepto, la última vigencia con `VIGENCIA_DESDE <=` la fecha de acreditación, con desempate por `ID`, sumadas—. **La versión de PHP es la que tiene pruebas**, y es la que corre en la aplicación; la de SQL se usa una vez y no vuelve.

---

## Pruebas

```bash
php tests/run.php cob_electronicos
php tests/run.php
```

123 casos, todos sin base salvo la última sección, que se saltea sola. Los criterios viven en **helpers estáticos puros**, al estilo de `Saldos::armarSaldosLocales()`: lo delicado de este módulo no son las consultas sino las decisiones.

| Qué se verifica | Helper |
| --- | --- |
| La tasa suma las alícuotas vigentes a la fecha del movimiento, y **no toma una vigencia posterior** | `tasaRetencion()` |
| El día de la vigencia ya rige la nueva; el anterior todavía la vieja | `tasaRetencion()` |
| Una alícuota inhabilitada no suma ni cuenta como concepto | `tasaRetencion()` |
| Dos vigencias del mismo día desempatan por `ID`, sin depender del orden de la lista | `tasaRetencion()` |
| Cero conceptos no es lo mismo que tasa cero | `tasaRetencion()` |
| El neto es `bruto * (1 - tasa)`, y el `0,969` del Excel es su complemento | `importeNeto()` |
| Una procesadora sin alícuotas vigentes **rechaza el alta** del movimiento, con el motivo | `resolverTasa()` |
| **Σ alícuotas >= 1 se rechaza al guardar la alícuota**, con la consecuencia de negocio | `validarAlicuota()` |
| Un concepto nuevo se puede cargar sin tocar código | `validarAlicuota()` |
| Al editar un %, un movimiento **ya acreditado conserva su `TASA_APLICADA` y su neto** | `planRecalculo()` |
| Sólo se recalculan los pendientes, y se informa la diferencia | `planRecalculo()` |
| Reenviar la misma tasa no cuenta como cambio (tolerancia de `DECIMAL(9,6)`) | `planRecalculo()` |
| Sin alícuotas no se recalcula nada y se avisa que conservan su tasa | `planRecalculo()` |
| El neto se imputa en la fecha de acreditación, **sin corrimientos** (probado con un sábado) | `armarSerie()` |
| Un movimiento anterior al eje queda `fuera_horizonte` con aviso, y **no** se reubica en la primera columna | `armarSerie()` |
| Una fecha del mes en curso anterior a hoy también queda afuera | `ubicacionEnEje()` |
| Un movimiento posterior al eje queda `fuera_horizonte` con su propio aviso | `armarSerie()` |
| La suma diaria y la mensual coinciden entre sí y con la suma de netos | `agruparPorDia()` / `agruparPorMes()` |
| **El total del cuadro son netos, nunca brutos** | `agruparPorDia()` |
| Dos movimientos de la misma procesadora y fecha se avisan y **siguen estando los dos** | `armarMovimientos()` |
| Cada fila queda marcada con su ubicación en el eje | `armarMovimientos()` |
| Sin tablas creadas, el proveedor rinde cero **con un aviso que dice qué falta**, y no el genérico de la clase base | `CobElectronicosProvider` |
| Sin procesadoras, sin movimientos, y con todo fuera del eje: cero con aviso propio en cada caso | `CobElectronicosProvider` |
| El eje más lo que quedó afuera son todos los netos | `CobElectronicosProvider` |

La sección contra la base verifica además que el cuadro de la pestaña y la serie del proveedor digan lo mismo: el neto de la pantalla tiene que ser el del tablero **más** lo que quedó fuera del horizonte.

`tests/test_providers.php` pasa a esperar **siete** módulos con datos reales, y `tests/test_menu.php` cuatro de seis en Ingresos.

### Verificado contra la base real

Con los dos scripts ya corridos:

| Qué se comprobó | Resultado |
| --- | --- |
| Movimientos migrados | **34** de los 38 de la hoja, por **533.228.141,01** de bruto |
| Los 4 que quedaron afuera | 68.740.834,11, todos con fecha anterior al día de la migración — **incluidos los tres que en el Excel nunca calcularon neto** |
| Neto y tasa | 34 de 34 con `TASA_APLICADA = 0,031` y `IMPORTE_NETO = bruto × 0,969`; **ningún neto en cero y ninguno que no verifique la fórmula** |
| Cuadro diario = cuadro mensual = total neto | 516.698.068,64 en los tres |
| Serie del proveedor | 516.698.068,64 en el eje, `fuera_horizonte` en cero, sin avisos |
| Fila del tablero | *Cobranzas Pagos Electrónicos* con 516.698.068,64 y el mismo detalle por día (9/9: 35.200.603,31) |
| Reejecutabilidad de la migración | Una segunda corrida no insertaría nada: 34 filas *"Ya estaba cargado"* + 4 *"Ya acreditado"*. Verificado corriendo su diagnóstico **sin la escritura** |

El `.env` del entorno apunta a `ENV=PROD`, así que los dos scripts se corrieron desde SSMS y no desde acá; las verificaciones de arriba son todas de sólo lectura.

---

## Usuario

Todavía no hay login. Las tres tablas tienen `USUARIO VARCHAR(50) NULL` y hoy se graba `NULL`. Los métodos de guardado ya reciben `$usuario` y los controllers lo resuelven con `usuarioActual()`, que lee `$_SESSION['usuario']`.

---

## Cómo probarlo a mano

1. **Correr el DDL.** `sql/cashflow_cob_electronicos.sql` contra `central`. Mirar el cuadro final: Payway y Mercado Pago tienen que salir con `CONCEPTOS_VIGENTES = 2`, `TASA_TOTAL = 0.031000` y `ESTADO = OK`. **Correrlo una segunda vez**: el cuadro tiene que dar exactamente lo mismo y no aparecer filas duplicadas.
2. **Cargar una alícuota.** Parámetros → Cob. Electrónicos. En *Alícuotas*, botón del lápiz en la fila `IIBB` de Payway → carga una vigencia nueva del 3% desde hoy. El recuadro tiene que anticipar la suma resultante (3,6%) y el mensaje del guardado tiene que decir cuántos movimientos pendientes se recalcularon. La fila vieja queda como `histórica`, no desaparece.
3. **Dar de alta un movimiento.** Pestaña Cob. Electrónicos → *Nuevo movimiento*. Payway, 1.000.000, fecha de mañana. La vista previa tiene que mostrar el neto estimado, y el mensaje del guardado el neto definitivo con la tasa usada. **Comprobar que el neto no se puede tipear**: la columna es texto en cursiva, no un input.
4. **Verlo en el cuadro diario.** Abajo, *Acreditaciones por día y por mes*: la fecha de mañana con su neto. El total de las dos tablas tiene que ser el mismo, y el mismo que la tarjeta *Importe Neto*.
5. **Verlo llegar al tablero.** Pestaña Cashflow, sección Disponibilidades, fila *Cobranzas Pagos Electrónicos*: el mismo neto en la columna de esa fecha. Clickeando el importe se vuelve a esta pestaña.
6. **Probar el caso del Excel.** Cargar un movimiento con fecha **anterior a hoy**: la fila aparece marcada *"ya acreditada · fuera del horizonte"*, el aviso de arriba explica que ya está en el saldo bancario, y el tablero **no** la muestra. Y desde Parámetros, intentar activar una procesadora nueva sin alícuotas: el switch está deshabilitado y el tooltip dice por qué.
7. **La migración, al final.** `sql/cashflow_cob_electronicos_migracion.sql`, después de verificar con Tesorería los tres movimientos sin fórmula. Revisar los dos cuadros que imprime y correrlo una segunda vez: el segundo cuadro tiene que decir *"Ya estaba cargado"* en todas las filas.

---

## Archivos

```
sql/cashflow_cob_electronicos.sql                     Las 3 tablas + semillas
sql/cashflow_cob_electronicos_migracion.sql           Los movimientos futuros del Excel
cashflow/Class/CobElectronicos.php                    Motor del modulo y helpers puros
cashflow/Class/Providers/CobElectronicosProvider.php  Serie COBRANZA
cashflow/Controller/CobElectronicosController.php
cashflow/Tabs/cob_electronicos.php                    Reemplaza el placeholder
cashflow/Tabs/parametros_cob_electronicos.php         Sub-pestaña de Parámetros
cashflow/Js/Cob-Electronicos.js
cashflow/Js/Parametros-Cob-Electronicos.js
cashflow/Css/Cob-Electronicos.css
tests/test_cob_electronicos.php
```

Modificados: `Class/CashflowRegistry.php` (`disponible => true` y la clase del proveedor) · `Class/Parametros.php` (módulo `COB_ELECTRONICOS` y sus dos secciones) · `Controller/ParametrosController.php` (ABM de procesadoras y alícuotas) · `Tabs/parametros.php` (el `tab-pane` y el script) · `Css/Parametros.css` (los estilos `pce-`) · `Class/Menu.php` (estado `DATOS`) · `tests/test_providers.php` (siete módulos) · `tests/test_menu.php` (el contador de Ingresos).

Ver `README-cashflow.md` y `README-saldos.md`.
