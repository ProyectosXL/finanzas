# Módulo Cob. Electrónicos — acreditaciones de las procesadoras de pago

Reemplaza el placeholder de la pestaña **Cob. Electrónicos** y alimenta la fila del tablero que hasta ahora rendía cero: *Cobranzas Pagos Electrónicos*, en la sección **Disponibilidades**.

Rama: `feature/cob-electronicos`

---

## La idea en una línea

**El neto no se tipea: se calcula, se persiste, y la tasa con la que se calculó se guarda al lado.** La carga se hace a mano o importando la planilla de la procesadora, que muestra las diferencias antes de escribir.

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

Decisión del negocio, tomada explícitamente: al guardar (o dar de baja) una alícuota, el servidor recalcula el neto de los movimientos **pendientes** de esa procesadora —los que tienen fecha de acreditación **de mañana en adelante**, ver [el corte](#lo-de-hoy-no-es-pendiente-el-corte-es-mañana)— y el mensaje dice **cuántos cambiaron y por cuánta plata**, con el detalle de tasa vieja → tasa nueva y neto viejo → neto nuevo.

> Lo propuesto era un botón explícito con vista previa, y la objeción sigue en pie: recalcular al editar un parámetro es la mitad del problema del Excel corriendo al revés —ahí un cambio de `D3` no llegaba a ningún movimiento, acá llega a todos los pendientes de una sola vez—. Se implementó automático porque así se pidió. Lo que compensa el riesgo es que **el recálculo nunca es silencioso**: el plan de cambios se calcula antes de escribir (`CobElectronicos::planRecalculo()`, que es un helper puro y devuelve un plan, no una escritura) y se informa completo en la respuesta. Si algún día se quiere volver al botón con vista previa, el plan ya está separado de la escritura y el cambio es sólo de dónde se lo llama.

**Los movimientos ya acreditados no se tocan nunca.** Su plata ya entró con la tasa con la que entró, y recalcularlos sería reescribir la historia. Es el mismo motivo por el que la tasa se persiste en la fila.

El recálculo escribe **en una transacción**: a mitad de camino quedaría una parte de los pendientes con la tasa nueva y otra con la vieja, sin ninguna forma de saber cuál es cuál.

---

## Un movimiento con fecha anterior al eje **no** abre el horizonte, y tampoco se avisa

**Es lo inverso a lo que hace Saldos, y es a propósito.**

`Saldos::destinoEnEje()` reubica un saldo viejo en la primera columna del eje. Este módulo **no**:

| | Qué describe el dato | Qué se hace con una fecha pasada |
| --- | --- | --- |
| **Saldos** | Plata que **existe ahora**: un saldo bancario, el efectivo del cajón de un local | Se reubica en la apertura del horizonte. Una fecha pasada significa *"esto ya es cierto hoy"* |
| **Cob. Electrónicos** | Un **movimiento ya ocurrido**: esa acreditación ya entró a la cuenta | Queda **fuera del alcance del módulo**, sin aviso |

El motivo es concreto: una acreditación con fecha pasada **ya la informa el saldo bancario de la pestaña Saldos**. Reubicarla en la apertura del horizonte la contaría **dos veces** — una como saldo y otra como cobranza futura.

### Y por qué no se avisa, si la regla general es no descartar nada en silencio

`fuera_horizonte` existe para una cosa: decir cuánta plata el tablero **debería** mostrar y no muestra. Una acreditación ya ocurrida no es eso. No le falta al tablero: **el tablero la muestra por otra fila**, la del saldo bancario. Contarla en `fuera_horizonte` haría que el tablero avisara todos los días por algo que ya pasó y sobre lo que no hay nada que hacer, y ese aviso taparía los que sí piden una acción.

Así que lo ya acreditado:

- no suma a la serie —eso nunca cambió, contarlo sería duplicar—,
- **no suma a `fuera_horizonte` ni deja aviso**,
- **no se muestra en la pestaña por defecto**: se trae con el switch *"Ver también las ya acreditadas"*, y ahí la fila se marca de forma neutra, en gris. No es un problema, ya pasó.

> Esto es un cambio de criterio respecto de la primera versión del módulo, que lo informaba como `fuera_horizonte` con aviso. La regla *"nunca se descarta en silencio"* sigue en pie para lo que el tablero deja de mostrar; lo que cambió es la lectura de este caso, que no es uno de esos.

Una fecha **posterior** al eje sí va a `fuera_horizonte` y sí deja aviso, con el importe y la fecha: **ésa** es plata que todavía no entró a ninguna cuenta y que ninguna otra fila del tablero muestra. La fila se ve en la pantalla marcada en rojo. Las dos marcas son distintas a propósito.

Cuando la fila del tablero queda en **cero** teniendo movimientos cargados, el proveedor igual lo explica —*"los movimientos cargados ya se acreditaron, así que no hay acreditaciones pendientes"*—, porque un cero sin explicación es exactamente lo que este módulo evita. La diferencia es que explica el cero, no reclama por el pasado.

> **El corte por fecha es explícito y no se delega a `Horizonte::acumular()`.** Una fecha del mes en curso anterior a hoy —un 1/9 con el eje arrancando el 6/9— caería en la columna del mes `2026-09`, que existe en el eje pero que el tablero **ni siquiera incluye en el arrastre** (`Horizonte::secuencia()` la deja afuera porque no representa ningún día futuro). El importe quedaría en una columna que nadie suma. Con el corte por fecha en `CobElectronicos::ubicacionEnEje()`, el criterio no depende de cómo quede armado el eje. Tiene prueba propia.

---

## Lo de hoy no es pendiente: el corte es mañana

**Decisión del negocio.** Una acreditación cuenta como **pendiente** recién desde **mañana**: lo que se acredita **hoy** ya está —o va a estar al cierre— en el saldo bancario que informa la pestaña Saldos en la primera columna del tablero, así que sumarlo también como cobranza lo contaría dos veces. Es el mismo argumento de la sección anterior, extendido al día en curso.

**Y es mañana a secas, no el próximo día hábil.** Se evaluó cortar en *"el siguiente día hábil"* y se descartó: puede haber acreditaciones cualquier día —una billetera acredita un sábado—, así que un sábado visto un viernes **es pendiente** y tiene que entrar al tablero. Es coherente con la regla de imputación de este módulo, que tampoco corre la fecha de acreditación a día hábil.

**El corte es uno solo.** `CobElectronicos::cortePendientes()` —helper puro, probado sin base— y lo usan los cuatro caminos, para que la pantalla y el tablero cierren:

| Camino | Qué hace con el corte |
| --- | --- |
| Pestaña (`getPestana()`) | Filtra por defecto desde el corte, marca cada fila y devuelve `pendientes_desde` para que la pantalla lo diga |
| Tablero (`CobElectronicosProvider`) | Pide los movimientos desde el corte y arma la serie con él |
| Recálculo (`recalcularPendientes()`) | Sólo recalcula lo que está en el corte o después |
| Importador (`compararImportacion()`) | Lo anterior al corte es *"ya acreditada"*: no se importa, y tampoco se propone dar de baja |

En la pantalla, el formulario de alta propone como fecha **mañana** y no hoy: un movimiento fechado hoy nacería *"ya acreditado"* y no entraría al tablero.

---

## Esta fila no se cruza con Ventas

**Decisión del negocio, ya tomada:** la fila *Cobranzas Pagos Electrónicos* **no se solapa ni se ajusta contra *Cobros s/ ventas estimadas*** de Ventas. No hay ninguna deducción, prorrateo ni exclusión cruzada entre las dos filas, y **no está pendiente**: es como se decidió medirlo.

Queda dicho acá con esas palabras para que no aparezca como "bug" más adelante. Es el mismo tipo de invariante que el de `COBROS_VENTAS` y `COBRANZAS_FR`, que tampoco se pisan (ver `README-cashflow.md`), sólo que acá el criterio no se deduce del dato sino que es una decisión.

---

## De dónde sale el dato

**Carga manual en pantalla, o importación de la planilla de la procesadora.** Las dos conviven: el formulario manual se mantiene siempre, como respaldo y para una corrección puntual.

Las tres columnas que la importación necesita se crearon desde el día uno —mismo criterio que Saldos con la API de Interbanking—, así que enchufarla no obligó a migrar ningún dato:

| Columna | Carga manual | Importación |
| --- | --- | --- |
| `ORIGEN_DATO` | `'MANUAL'` | `'ARCHIVO'` (y `'API'` queda para el día que haya una) |
| `ID_EXTERNO` | `NULL` | Número de liquidación de la procesadora, si el archivo lo trae |
| `ARCHIVO_ORIGEN` | `NULL` | Nombre del archivo que se importó |

**`ID_EXTERNO` es la clave con la que la importación reconoce un movimiento ya cargado.** Va con índice único **filtrado** por procesadora (`WHERE ID_EXTERNO IS NOT NULL`), igual que el `CBU` de Saldos: sigue estando en `NULL` en todas las filas cargadas a mano, y un `UNIQUE` común de SQL Server admite un solo `NULL`.

---

## El importador

### Por qué existe

Cargar treinta acreditaciones por semana a mano hace que la pantalla se actualice poco, y **una pantalla que se actualiza poco muestra un tablero viejo**. El problema no era tipear: era tener que comparar el archivo de la procesadora contra lo ya cargado, fila por fila, para saber qué había cambiado. El importador da vuelta eso: se sube el archivo completo y **el módulo dice qué cambiaría**.

### Dos pasos, y el segundo no confía en el primero

**Previsualizar** no escribe nada: lee el archivo, lo compara con lo cargado y devuelve el diff. **Confirmar** aplica.

El segundo paso **vuelve a leer la base, vuelve a validar cada fila y vuelve a calcular el diff con el mismo helper puro**. Del navegador llegan sólo los datos de entrada del archivo —procesadora, bruto, fecha, número de liquidación, observaciones—, los mismos que se tipearían a mano; **la tasa y el neto los sigue calculando el servidor**. Si entre la previsualización y la confirmación cambió algo —otro usuario cargó un movimiento, alguien editó una alícuota—, lo que se aplica es el diff contra el estado real y no contra el que se dibujó.

Que el diff viva en un helper puro (`compararImportacion()`) es lo que hace posible eso: se calcula dos veces con la misma regla. Si viviera dentro de la escritura, la pantalla mostraría una cosa y el servidor haría otra.

### Por qué CSV y no `.xlsx`

Leer un `.xlsx` sin librerías necesita la extensión `zip` de PHP. En este servidor el `php_zip.dll` **está instalado pero la extensión está comentada en `php.ini`**: habilitarla es tocar la configuración del servidor, reiniciar Apache, y dejar el módulo dependiendo de que ese cambio esté hecho en cada entorno. Excel abre y guarda CSV nativamente (*Archivo → Guardar como → CSV UTF-8*), así que el costo para el usuario es un paso y el módulo no depende de nada.

Si igual suben un `.xlsx`, el parser **lo detecta por su firma** (un `.xlsx` es un ZIP y empieza con `PK`) y responde con la instrucción de cómo convertirlo, en lugar de fallar con un archivo lleno de bytes binarios. Lo mismo con un `.xls` antiguo.

### La plantilla se descarga y vuelve a entrar

La plantilla va con BOM de UTF-8 y separador `;`, que es lo que Excel en español abre en columnas sin preguntar nada. Sin el BOM, Excel muestra los acentos rotos; con coma, mete todo en una sola columna.

**Trae dos filas de ejemplo cargables**, no comentadas: una plantilla con el ejemplo comentado obliga a adivinar el formato del número y de la fecha, que es justo donde falla una importación. Una prueba verifica que la plantilla que se descarga vuelva a entrar por el parser — si no, el formato que se propone no sería el que se acepta.

Las columnas y sus sinónimos se definen **una sola vez** (`columnasImportacion()`), y de ahí salen la plantilla, el mapeo del encabezado y la ayuda de la pantalla. Con tres listas separadas, se desincronizan en el primer cambio.

| Columna | Obligatoria | Sinónimos aceptados |
| --- | --- | --- |
| `PROCESADORA` | sí | `RAZON_SOCIAL`, `RAZON_SOC` |
| `IMPORTE_BRUTO` | sí | `IMPORTE`, `BRUTO` |
| `FECHA_ACREDITACION` | sí | `FECHA`, `COBRO`, `FECHA_COBRO` |
| `ID_EXTERNO` | no | `LIQUIDACION`, `NRO_LIQUIDACION` |
| `OBSERVACIONES` | no | `OBSERVACION`, `NOTAS` |

Los sinónimos incluyen **los nombres de la hoja original** (`RAZON_SOC`, `Importe`, `Cobro`) para poder pegar las columnas del Excel viejo sin renombrar nada. Los títulos se comparan sin acentos, sin espacios y sin mayúsculas, y una columna de más no molesta — una columna de neto en el archivo, por ejemplo, **se ignora**: el neto lo calcula el servidor, y eso tiene prueba.

### El formato del número y de la fecha no lo tiene que saber el usuario

El parser acepta lo que exporta Excel en cualquiera de las dos configuraciones regionales:

- `1069326,00` y `1069326.00`; `3.757.900,50` y `3,757,900.50`. Con los dos separadores presentes, el que está más a la derecha es el decimal. Con uno solo, es decimal salvo que aparezca más de una vez.
- `07/09/2026`, `2026-09-07`, `7-9-2026`, `07/09/26`, y el **serial de Excel** (`46000`) acotado a 1954–2064, que es la red para una columna que quedó con formato número.

Lo que **no** se adivina: una fecha que no existe (`31/02/2026`) o sin año devuelve `null`, y la fila queda como error **con su número de línea**. Y un importe que no es un número devuelve `null` y no cero: la diferencia entre *"no es un número"* y *"es cero"* es lo que hace que la fila sea un error en vez de un movimiento de cero pesos.

### Cómo se reconoce un movimiento ya cargado

| Si la fila del archivo… | La clave es |
| --- | --- |
| trae `ID_EXTERNO` | (procesadora, `ID_EXTERNO`) — la clave de verdad |
| no lo trae | (procesadora, fecha de acreditación) |

Con `ID_EXTERNO` **manda el número de liquidación por encima de la fecha**, y eso resuelve el caso en que la procesadora **reprograma** una acreditación: el movimiento se reconoce y se actualiza la fecha, en lugar de proponer un alta y dejar el viejo colgado.

**Dos filas con la misma clave y sin `ID_EXTERNO` son un error, no un aviso.** Dos liquidaciones el mismo día son legítimas —por eso la tabla no tiene `UNIQUE (procesadora, fecha)`—, pero sin el número no hay forma de saber cuál de las dos corresponde a cuál de las cargadas. El error pide llenar `ID_EXTERNO` en las dos, que es la solución real, y dice contra qué línea choca.

### Los seis resultados posibles de una fila

| Estado | Qué pasa |
| --- | --- |
| **nueva** | No estaba cargada → se inserta, con `ORIGEN_DATO = 'ARCHIVO'` y el nombre del archivo |
| **cambia** | Cambió el importe bruto o la fecha → se actualiza y **se recalcula la tasa y el neto** |
| **igual** | Ya estaba cargada idéntica → **no se toca**. Pisarle `FECHA_UPDATE` a todo lo que el archivo repite dejaría la columna diciendo que se editó todo en cada importación, igual que el diff de las sucursales de Saldos |
| **ya acreditada** | Fecha anterior al corte de pendientes (hoy incluido) → **no se importa** y no es un error. El archivo de la procesadora siempre trae el histórico, y cargarlo no aporta nada |
| **problema** | Procesadora inexistente o inhabilitada, importe no numérico o ≤ 0, fecha ilegible, sin alícuota vigente, clave repetida |
| **ya no viene** | Está cargado y el archivo no lo trae → **candidato a baja** |

Sólo se comparan el bruto y la fecha, que son los datos de entrada. La tasa y el neto no se comparan: son derivados, y si cambiaron sin que cambie el bruto es porque cambió la alícuota — y eso lo resuelve el recálculo de pendientes, no una importación.

### Con un solo error no se importa nada

El archivo es la fuente de verdad. Importar la mitad deja un estado que **la próxima importación no puede explicar**: las filas que quedaron afuera aparecerían como altas nuevas, mezcladas con las de verdad. Así que el botón queda deshabilitado y el aviso dice qué corregir, con la línea de cada problema.

Todo lo que sí se importa va **en una transacción**, por lo mismo.

### Las bajas: lo que hace que no haya que comparar a mano

Sin esto, una acreditación que la procesadora dio de baja se queda para siempre en el tablero, porque ninguna importación la menciona.

El alcance está acotado a propósito, y es la parte delicada: sólo se consideran los movimientos **de las procesadoras que vienen en el archivo**, con fecha **dentro del período que el archivo cubre**, y **desde el corte de pendientes** —lo ya acreditado, lo de hoy incluido, no se toca nunca, ni declarando un período largo—. Un archivo parcial no puede proponer dar de baja lo que no estaba mirando.

Y la baja **nunca se aplica sola**: se lista, hay que marcar la casilla y además confirmar.

> **El período se puede declarar, y hay un caso en que hace falta.** Si no se declara, se infiere de las fechas de las filas del archivo. Eso cubre lo habitual, pero **no** el caso en que la procesadora da de baja la **primera** o la **última** acreditación del período: esa fecha desaparece del archivo, así que el rango inferido se encoge y el movimiento cargado queda justo afuera de la ventana. Los dos campos *"el archivo cubre desde / hasta"* existen para eso — el usuario sabe qué período exportó. **Nunca se adivina**: sin declararlo, esa baja simplemente no se propone, y el panel dice qué ventana se usó y si la declaró el usuario o se infirió. Verificado con datos reales: con la ventana inferida, cero bajas; declarando el período, aparece la que el archivo dejó de traer.

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

Las acreditaciones **ya ocurridas no se muestran, las de hoy incluidas**: el filtro arranca mañana (`pendientes_desde` en el payload) y hay un switch para traerlas. Es lo que hace que el total de la pantalla coincida con el del tablero sin tener que explicar una diferencia. Cuando se traen, van en gris y marcadas *"ya acreditada"* — la marca es neutra, no roja: no son un problema.

El formulario de alta dibuja una **vista previa del neto** mientras se tipea, y dice explícitamente que el definitivo lo calcula el servidor con la alícuota vigente a la fecha elegida. La previa usa la tasa vigente **a hoy**, que es la que trae el payload; si la fecha de acreditación cae en otra vigencia, el mensaje del guardado informa la que se usó de verdad.

El alta sólo ofrece las procesadoras **activas y con alícuota vigente**: son las únicas que pueden calcular un neto, y ofrecer las otras llevaría a un rechazo del servidor después de tipear todo el movimiento. El **filtro**, en cambio, ofrece todas, incluidas las inhabilitadas: puede haber movimientos de una procesadora que después se dio de baja, y no poder filtrarlos los esconde.

**Estado vacío honesto:** las tarjetas dicen `sin cargar`, no `$ 0,00`. Un cero se leería como *"no hay acreditaciones previstas"*, que no es lo mismo que *"no se cargó ninguna"*.

### El cuadro por día y por mes

Es la agrupación que consume el tablero: suma de netos por fecha de acreditación —`GROUP BY FECHA_ACREDITACION` y por año-mes—, **sin corrimiento a día hábil ni tratamiento de feriados**. La fecha que informa la procesadora es la fecha en que el dinero entra; correrla al lunes inventaría una fecha que el dato ya trae. Tiene prueba con un sábado.

Las dos agrupaciones son la misma suma vista de dos formas, así que **dan el mismo total**, y ese total es el neto y nunca el bruto. Las tres cosas están probadas juntas, porque son el invariante que habría que romper para que la pantalla y el tablero no cierren.

Los días **posteriores** al eje se ven marcados en rojo, no escondidos, con la nota de por qué. Los ya acreditados —cuando se los trae con el switch— van en gris.

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

### El resultado del guardado se muestra en la pantalla, no en un alert

Guardar una alícuota puede recalcular movimientos, y lo que hay que mostrar de eso es una **tabla**: qué movimiento, con qué tasa antes y después, con qué neto antes y después, y la diferencia. Una tabla en un `alert()` con saltos de línea no se puede leer ni comparar, y desaparece con un click justo cuando uno quiere seguir mirándola.

Así que el resultado va a un panel arriba de las dos secciones, con esa tabla, la tasa total vigente que quedó, el porcentaje del bruto que va a acreditar un movimiento nuevo, y los avisos del recálculo. Queda a la vista mientras se sigue trabajando. El texto se escapa: los nombres de las procesadoras los tipea un usuario.

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
| Hay procesadoras pero ningún movimiento pendiente | Distingue *"no hay datos"*, *"ya se acreditaron todos"* y *"los datos son cero"*. La segunda consulta corre **sólo** en el caso vacío, que es cuando hace falta la explicación |
| Un movimiento cuya procesadora perdió sus alícuotas vigentes | Que **suma igual, con el neto que ya tenía guardado**, pero que no se van a poder cargar movimientos nuevos |
| Movimientos **posteriores** al horizonte | El importe, la fecha, y por qué no entran |
| La fila queda en cero teniendo movimientos | El motivo del cero: o ya se acreditaron todos, o son todos posteriores al eje. Se decide con los escalares de la serie y **no** con el filtro de la consulta, para que el aviso sea el correcto sin importar cómo se pidieron los movimientos |

Lo ya acreditado **no genera aviso**. Ver la sección de arriba.

Los movimientos se piden **desde el corte de pendientes** (`CobElectronicos::cortePendientes()`, mañana), que es una optimización: la regla vive igual en `armarSerie()` y está probada, así que no depende de que el llamador se acuerde de filtrar.

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
php cashflow/tests/run.php cob_electronicos
php cashflow/tests/run.php
```

226 casos, todos sin base salvo la última sección, que se saltea sola. Los criterios viven en **helpers estáticos puros**, al estilo de `Saldos::armarSaldosLocales()`: lo delicado de este módulo no son las consultas sino las decisiones.

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
| Un movimiento anterior al eje **no** se reubica en la primera columna | `armarSerie()` |
| Y **no** suma a `fuera_horizonte` ni deja aviso; se devuelve aparte como dato | `armarSerie()` |
| Con sólo movimientos ya acreditados, la serie va en cero y muda | `armarSerie()` |
| Una fecha del mes en curso anterior a hoy también queda afuera | `ubicacionEnEje()` |
| **Lo pendiente arranca mañana**: un viernes, el sábado (no se corre al lunes); cruza mes y año | `cortePendientes()` |
| **Un movimiento con fecha de hoy está en el eje pero cuenta como ya acreditado** | `ubicacionEnEje()` |
| Visto un viernes, el sábado es pendiente y el viernes mismo no | `ubicacionEnEje()` |
| Un movimiento de hoy **no suma en la columna de hoy** ni en ninguna; el mismo fechado el primer día pendiente sí | `armarSerie()` |
| Un movimiento de hoy **no se recalcula** al cambiar una alícuota | `planRecalculo()` |
| Un movimiento posterior al eje sí queda `fuera_horizonte`, con su aviso y su importe | `armarSerie()` |
| `fuera_eje` cuenta sólo lo posterior; lo ya acreditado se cuenta aparte | `armarMovimientos()` |
| La suma diaria y la mensual coinciden entre sí y con la suma de netos | `agruparPorDia()` / `agruparPorMes()` |
| **El total del cuadro son netos, nunca brutos** | `agruparPorDia()` |
| Dos movimientos de la misma procesadora y fecha se avisan y **siguen estando los dos** | `armarMovimientos()` |
| Cada fila queda marcada con su ubicación en el eje | `armarMovimientos()` |
| Sin tablas creadas, el proveedor rinde cero **con un aviso que dice qué falta**, y no el genérico de la clase base | `CobElectronicosProvider` |
| Sin procesadoras, sin movimientos, y con todo ya acreditado: cero con aviso propio en cada caso | `CobElectronicosProvider` |
| Con todo ya acreditado, el aviso explica el cero y **no** menciona nada anterior | `CobElectronicosProvider` |
| Con sólo un movimiento de hoy, la fila va en cero y la columna de hoy no lo suma | `CobElectronicosProvider` |
| El eje más lo posterior son todos los netos pendientes | `CobElectronicosProvider` |

Del importador:

| Qué se verifica | Helper |
| --- | --- |
| Los importes se leen en las dos configuraciones regionales de Excel, con y sin separador de miles, con símbolo de moneda | `numeroDesdePlanilla()` |
| Un vacío o un texto devuelven `null` y **no** cero | `numeroDesdePlanilla()` |
| Las fechas se leen en cinco formatos, incluido el serial de Excel | `fechaDesdePlanilla()` |
| Una fecha que no existe o sin año **no se adivina** | `fechaDesdePlanilla()` |
| **La plantilla que se descarga vuelve a entrar por el parser** | `plantillaCsv()` + `parsearPlanilla()` |
| El separador se detecta solo (`;` y `,`) | `parsearPlanilla()` |
| Acepta los títulos de la hoja original (`RAZON_SOC`, `Importe`, `Cobro`) | `parsearPlanilla()` |
| Las filas vacías que Excel deja abajo se saltean; falta de columna obligatoria y archivo sin datos avisan | `parsearPlanilla()` |
| Un `.xlsx` se detecta por su firma y el mensaje dice cómo convertirlo | `parsearPlanilla()` |
| Alta, cambio de importe, cambio de fecha, sin cambios y ya acreditada, cada uno con su motivo | `compararImportacion()` |
| **El neto de una columna del archivo se ignora**: lo calcula el servidor | `compararImportacion()` |
| Las seis clases de fila inválida se rechazan, cada una con su motivo y su línea | `compararImportacion()` |
| Con un solo error **no se importa nada**, y el aviso dice por qué es todo o nada | `compararImportacion()` |
| Dos filas con la misma clave sin `ID_EXTERNO` son un error que pide llenarlo, y dice contra qué línea choca | `compararImportacion()` |
| Con `ID_EXTERNO`, dos liquidaciones del mismo día entran las dos | `compararImportacion()` |
| Una acreditación reprogramada se reconoce por su `ID_EXTERNO` aunque cambie la fecha, sin proponer un alta | `compararImportacion()` |
| Las bajas se proponen sólo dentro de la ventana y de las procesadoras del archivo, nunca sobre lo ya acreditado ni sobre otra procesadora | `compararImportacion()` |
| Con la ventana inferida, una baja anterior a la primera fila **no** se propone; declarando el período, sí | `compararImportacion()` |
| Un archivo que no cambia nada lo dice y no se puede importar | `compararImportacion()` |

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
| Importador, contra los 34 movimientos reales | Un archivo armado desde lo cargado, con una fila de más, una con otro importe y una quitada: el diff dijo **1 alta, 1 cambio, 14 iguales, 1 ya acreditada, 0 errores**, con la diferencia de neto exacta. Con la ventana inferida, 0 bajas; declarando el período, propuso la única que el archivo dejó de traer. **Nada se escribió**: 34 movimientos antes y después |
| Los endpoints por HTTP, contra Apache | La plantilla baja con `text/csv; charset=UTF-8`, su `Content-Disposition` y el BOM. `getPestana` responde el filtro efectivo (`desde` = inicio del eje, por defecto). La previsualización con una subida real `multipart` detectó el cambio contra la fila cargada, ignoró la ya acreditada y rechazó la procesadora inexistente |
| El "todo o nada" lo hace cumplir el servidor | Confirmando **con las filas con problemas incluidas**, el servidor respondió *"El archivo tiene 1 fila(s) con problemas, así que no se importó nada"* y la base quedó en 34 movimientos. La regla no depende de que el navegador filtre nada |
| Las tres sentencias de escritura del importador | `INSERT` con `ORIGEN_DATO='ARCHIVO'` + `ID_EXTERNO` + `ARCHIVO_ORIGEN`, `UPDATE` y baja lógica, ejecutadas contra la base real y **deshechas con un `rollback` explícito**: las tres válidas, el índice único filtrado de `ID_EXTERNO` rechazó un segundo movimiento con el mismo número de liquidación, y la tabla volvió a 34 filas |

Lo único que queda sin ejecutar es una importación real de punta a punta, porque escribiría en producción: es el paso 6 del plan de abajo.

El `.env` del entorno apunta a `ENV=PROD`, así que los dos scripts se corrieron desde SSMS y no desde acá; las verificaciones de arriba son todas de sólo lectura.

---

## Usuario

Todavía no hay login. Las tres tablas tienen `USUARIO VARCHAR(50) NULL` y hoy se graba `NULL`. Los métodos de guardado ya reciben `$usuario` y los controllers lo resuelven con `usuarioActual()`, que lee `$_SESSION['usuario']`.

---

## Cómo probarlo a mano

1. **Correr el DDL.** `sql/cashflow_cob_electronicos.sql` contra `central`. Mirar el cuadro final: Payway y Mercado Pago tienen que salir con `CONCEPTOS_VIGENTES = 2`, `TASA_TOTAL = 0.031000` y `ESTADO = OK`. **Correrlo una segunda vez**: el cuadro tiene que dar exactamente lo mismo y no aparecer filas duplicadas.
2. **Cargar una alícuota.** Parámetros → Cob. Electrónicos. En *Alícuotas*, botón del lápiz en la fila `IIBB` de Payway → carga una vigencia nueva del 3% desde hoy. El recuadro tiene que anticipar la suma resultante (3,6%), y arriba tiene que aparecer el **panel verde con la tabla** del recálculo: qué movimientos cambiaron, con qué tasa antes y después y con qué diferencia de neto. La fila vieja queda como `histórica`, no desaparece.
3. **Dar de alta un movimiento.** Pestaña Cob. Electrónicos → *Nuevo movimiento*. Payway, 1.000.000, fecha de mañana. La vista previa tiene que mostrar el neto estimado, y el mensaje del guardado el neto definitivo con la tasa usada. **Comprobar que el neto no se puede tipear**: la columna es texto en cursiva, no un input.
4. **Verlo en el cuadro diario.** Abajo, *Acreditaciones por día y por mes*: la fecha de mañana con su neto. El total de las dos tablas tiene que ser el mismo, y el mismo que la tarjeta *Importe Neto*.
5. **Verlo llegar al tablero.** Pestaña Cashflow, sección Disponibilidades, fila *Cobranzas Pagos Electrónicos*: el mismo neto en la columna de esa fecha. Clickeando el importe se vuelve a esta pestaña.
6. **Importar una planilla.** *Importar planilla* → *Descargar plantilla* → abrila en Excel, dejá las dos filas de ejemplo o pegá las acreditaciones reales, guardá como CSV y subila. *Ver diferencias* tiene que mostrar los chips del resumen y la tabla de lo que cambia **sin haber escrito nada**. Confirmá, y comprobá que la tabla de arriba y el cuadro diario se actualizan. Después **volvé a subir el mismo archivo**: tiene que decir que no cambia nada y dejar el botón deshabilitado — eso es lo que permite importar seguido sin miedo.
7. **Probar las bajas del importador.** Sacá una fila del medio del archivo y volvé a subirlo: aparece en *"Cargados que el archivo no trae"* con su importe, y la baja **no** se aplica salvo que marques la casilla. Si la fila que sacás es la primera o la última del período, completá *el archivo cubre desde / hasta* para que la detecte; el panel dice qué ventana usó y si la declaraste vos.
8. **Probar el caso del Excel.** Cargá un movimiento con fecha **de hoy o anterior**: no aparece en la tabla —el filtro arranca mañana— y **el tablero no avisa nada**. Prendé *"Ver también las ya acreditadas"* y ahí sí se ve, en gris y marcada. Y desde Parámetros, intentá activar una procesadora nueva sin alícuotas: el switch está deshabilitado y el tooltip dice por qué.
9. **La migración, al final.** `sql/cashflow_cob_electronicos_migracion.sql`, después de verificar con Tesorería los tres movimientos sin fórmula. Revisar los dos cuadros que imprime y correrlo una segunda vez: el segundo cuadro tiene que decir *"Ya estaba cargado"* en todas las filas.

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

Modificados: `Class/CashflowRegistry.php` (`disponible => true` y la clase del proveedor) · `Class/Parametros.php` (módulo `COB_ELECTRONICOS` y sus dos secciones) · `Controller/ParametrosController.php` (ABM de procesadoras y alícuotas) · `Tabs/parametros.php` (el `tab-pane` y el script) · `Css/Parametros.css` (los estilos `pce-`, incluido el panel de resultado) · `Class/Menu.php` (estado `DATOS`) · `tests/test_providers.php` (siete módulos) · `tests/test_menu.php` (el contador de Ingresos).

El importador no agregó archivos: la plantilla, el parseo y el diff viven en `Class/CobElectronicos.php` con el resto de los criterios del módulo, sus dos endpoints en `Controller/CobElectronicosController.php`, y su panel en la pestaña. **Ninguna dependencia nueva**: el CSV se lee con `str_getcsv` y la conversión de codificación con `mbstring`, que ya estaba en uso.

Ver `README-cashflow.md` y `README-saldos.md`.
