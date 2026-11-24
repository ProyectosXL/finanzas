---
titulo: Cobranzas Electrónicas
pestana: cob_electronicos
categoria: Ingresos
estado: completa
actualizado: 2026-11-24
usuarios: Tesorería, Administración
bajada: Lo que las procesadoras de pago van a depositar en el banco a partir de mañana, neto de retenciones, cargado a mano o importado de su planilla.
---

## Para qué sirve

Muestra las acreditaciones que las procesadoras de pago informaron y que todavía no entraron al banco: cuánto informan (el importe bruto), cuánto retienen y cuánto entra de verdad a la cuenta (el importe neto), y en qué fecha.

Lo que se acredita **hoy o antes** no está acá: esa plata ya está —o va a estar al cierre del día— en el saldo bancario de la pestaña Saldos. Contarla también como cobranza la sumaría dos veces. Por eso la pestaña proyecta **desde mañana**.

Lo que muestra entra al tablero en la fila **Cobranzas Pagos Electrónicos**, de la sección Disponibilidades. Al tablero va sólo el neto. Ver [cómo se lee el tablero](ayuda:general_tablero).

## De dónde salen los datos

:::diagrama
fuente: La carga a mano | cada acreditación, con su procesadora, su importe bruto y su fecha
fuente: La planilla de la procesadora | el Excel con las acreditaciones informadas, importado desde la pestaña
fuente: Parámetros › Cob. Electrónicos | las procesadoras y sus alícuotas de retención, con su vigencia
fuente: Parámetros › Calendario | el horizonte del tablero
pestana: Cob. Electrónicos | calcula el neto y separa lo pendiente de lo ya acreditado
tablero: Fila Cobranzas Pagos Electrónicos | el neto de cada día, desde mañana
alt: La carga a mano, la planilla de la procesadora, Parámetros › Cob. Electrónicos y Parámetros › Calendario alimentan la pestaña, que pasa al tablero el neto de lo que se acredita desde mañana.
:::

- **La carga a mano**: un movimiento por acreditación, cargado con **Nuevo movimiento**. Queda con origen **Manual**.
- **La planilla de la procesadora**: el Excel con las acreditaciones, pegado en la plantilla de la pestaña o con sus propios títulos, y subido con **Importar planilla**. Lo que entra así queda con origen **Archivo**.
- **Parámetros › Cob. Electrónicos**: quién deposita (las procesadoras) y cuánto retiene cada una (las alícuotas, por concepto y con fecha de vigencia). No hay una lista fija de procesadoras: son las que estén cargadas ahí. La instalación cargó **Payway** y **Mercado Pago**, con IIBB 2,5 % y SICREB 0,6 %.
- **Parámetros › Calendario**: las columnas diarias y mensuales del tablero, que dicen hasta qué fecha llega el horizonte.

La pestaña no lee Tango ni otra aplicación: todo lo que muestra se cargó acá o se importó acá.

## Cómo se calcula

### El neto

El importe bruto y la fecha de acreditación son los datos que se cargan. La tasa y el neto los calcula la app, nunca se tipean:

:::formula
tasa aplicada = suma de las alícuotas vigentes de la procesadora a la fecha de acreditación
importe neto = importe bruto × (1 − tasa aplicada)
retenciones = importe bruto − importe neto
:::

- **La tasa es la vigente a la fecha de acreditación**, no a la fecha de carga. Para cada concepto (IIBB, SICREB o el que se cargue) vale la vigencia más nueva que empezó ese día o antes. Una vigencia que empieza más adelante todavía no cuenta.
- **Sin alícuotas vigentes a esa fecha, el movimiento no se puede guardar**: no hay con qué calcular el neto. Tampoco si las alícuotas suman 100 % o más.
- **La tasa y el neto quedan guardados con el movimiento.** Lo que cambie después en Parámetros no reescribe lo ya acreditado. Sí recalcula lo pendiente: ver [Parámetros](ayuda:cob_electronicos#parametros).

:::ejemplo ilustrativo
Una procesadora con dos alícuotas vigentes: IIBB 2,5 % y SICREB 0,6 %. Tasa aplicada: **3,1 %**.

- Importe bruto informado: **$ 1.000.000**.
- Retenciones: 3,1 % × $ 1.000.000 = **$ 31.000**.
- Importe neto: $ 1.000.000 − $ 31.000 = **$ 969.000**.

El tablero recibe los $ 969.000, en la fecha de acreditación. Los $ 31.000 nunca llegan al banco.
:::

### Pendiente, ya acreditada y posterior al horizonte

Cada movimiento cae en uno de tres lugares, según su fecha de acreditación:

| Fecha | Cómo se ve | ¿Va al tablero? | ¿Avisa? |
| --- | --- | --- | --- |
| Hoy o antes | **ya acreditada**, en gris. No se muestra salvo que se pida | No: la informa el saldo bancario de Saldos | No |
| Desde mañana, dentro del horizonte | Normal | Sí, en la columna de su fecha | No |
| Después del final del horizonte | **posterior al horizonte**, en rojo | No: su fecha no tiene columna | Sí |

**Mañana es mañana a secas**, no el próximo día hábil: una billetera puede acreditar un sábado, y un sábado visto un viernes es pendiente.

**La fecha va tal cual.** El neto se imputa el día de acreditación, sin correrlo a día hábil ni saltear feriados.

### Lo que no hace

- **No suma brutos.** Al tablero va sólo el neto.
- **No se cruza con Ventas.** La fila de esta pestaña y la de cobros sobre ventas estimadas de Ventas no se descuentan entre sí: es una decisión del negocio.

## La pantalla

La pantalla, de arriba hacia abajo:

- El panel de avisos, con los de la pestaña y los que el tablero dice de ella.
- Las confirmaciones verdes de lo que se acaba de guardar. Se van solas a los 8 segundos.
- Las cuatro tarjetas.
- La tarjeta **Acreditaciones informadas**, con los botones **Exportar**, **Actualizar**, **Importar planilla** y **Nuevo movimiento**.
- El formulario de alta y el panel **Importar desde planilla**, cuando se abren.
- Los filtros, el rótulo del horizonte y el interruptor **Ver también las ya acreditadas**.
- La tabla de movimientos, con el pie **TOTALES**.
- **Totales por procesadora**.
- **Acreditaciones por día y por mes**.

No tiene vistas de días y meses como el tablero, ni solapas.

### Las tarjetas

| Tarjeta | Qué muestra |
| --- | --- |
| **Importe Bruto** | La suma de los brutos de la tabla. Abajo, cuántos movimientos son. |
| **Retenciones** | Bruto menos neto. Abajo dice "IIBB, SICREB y lo que se cargue". |
| **Importe Neto** | La suma de los netos de la tabla. Abajo, "Es lo que el tablero consume" y la retención promedio. |
| **Posterior al Horizonte** | El neto de los movimientos con fecha después del final del horizonte, y cuántos son. Si no hay, un guión y "Todo lo pendiente entra al horizonte". |

Sin movimientos, las tres primeras dicen **sin cargar**, no $ 0.

Las tarjetas suman **lo que muestra la tabla**, con los filtros puestos. Ver *Los filtros y las ya acreditadas*, más abajo.

### Los filtros y las ya acreditadas

- **Procesadora**: todas, también las inhabilitadas, que dicen "(inhabilitada)".
- **Acreditación desde** y **hasta**, y el botón **Filtrar**.
- **Limpiar**: vacía los filtros y también apaga el interruptor.
- **Horizonte: dd/mm/aaaa a dd/mm/aaaa**: de hoy al final del horizonte del tablero.
- **Ver también las ya acreditadas (fecha de hoy o anterior)**: el interruptor. Recarga la tabla al tocarlo.

Al abrir, la tabla muestra **desde mañana**, aunque el campo **Acreditación desde** se vea vacío. Las ya acreditadas aparecen de dos formas:

- prendiendo **Ver también las ya acreditadas**: trae todo, desde el primer movimiento cargado;
- o escribiendo en **Acreditación desde** una fecha de hoy o anterior.

**Con las ya acreditadas a la vista, suman en la pantalla**: en las cuatro tarjetas (salvo **Posterior al Horizonte**), en el pie **TOTALES**, en **Totales por procesadora** y en los cuadros por día y por mes. **En el tablero no suman nunca.** Se ven en gris, con la marca **ya acreditada**.

### La tabla de movimientos

| Columna | Qué muestra |
| --- | --- |
| **Procesadora** | La procesadora. Debajo, las observaciones, el número de liquidación ("Liquidación …") y el archivo del que vino ("Archivo …"), si los tiene. |
| **Importe bruto** | Lo que informó la procesadora. |
| **Fecha de acreditación** | El día que entra la plata. Puede llevar **ya acreditada** (gris) o **posterior al horizonte** (rojo); al pasar el mouse dice por qué. |
| **Tasa aplicada** | La suma de alícuotas con la que se calculó, en cursiva. Queda guardada: editar la alícuota no reescribe lo ya informado. |
| **Importe neto** | Bruto × (1 − tasa). |
| **Origen** | **Manual** o **Archivo**, y el ícono que dice quién lo cargó o lo cambió por última vez, y cuándo. |
| **Acciones** | El lápiz para corregir y el botón para dar de baja. |

La fila entera va en rojo si es posterior al horizonte y en gris si ya se acreditó. Sin filas, la tabla dice "No hay acreditaciones cargadas con este filtro." o "No hay acreditaciones cargadas todavía.".

El pie **TOTALES** suma el bruto, la retención promedio, lo retenido y el neto de las filas que se ven.

### Totales por procesadora

Una fila por procesadora: **Movimientos**, **Importe bruto**, **Retenciones** e **Importe neto**, y el pie **TOTAL GENERAL**. Responde a los mismos filtros que la tabla de movimientos.

### Acreditaciones por día y por mes

La suma de netos agrupada por fecha de acreditación, en dos cuadros: **Por día** (**Fecha**, **Mov.**, **Neto acreditado**) y **Por mes** (**Mes**, **Mov.**, **Neto acreditado**). Los dos dan el mismo total. En **Por día**, una fecha posterior al horizonte lleva **posterior** en rojo y una ya acreditada, **ya acreditada** en gris.

Debajo, la nota dice desde qué día arranca lo pendiente: "Lo pendiente arranca mañana (dd/mm/aaaa)…".

Cada tabla tiene su **Exportar**, que baja a Excel lo que se está viendo.

### Sin permiso de edición

No se ven **Importar planilla**, **Nuevo movimiento** ni los botones de la columna **Acciones**. Todo lo demás sí: los filtros, el interruptor y **Exportar**. Ver [permisos y parámetros](ayuda:general_permisos_parametros#si-no-tenes-permiso-para-editar).

## Tareas frecuentes

### Cargar una acreditación a mano

:::pasos
1. Tocá **Nuevo movimiento**.
2. Elegí la **Procesadora**. Sólo aparecen las activas con alícuota vigente, con su retención al lado.
3. Escribí el **Importe bruto**, el que informa la procesadora.
4. Revisá la **Fecha de acreditación**. Si el campo estaba vacío, propone mañana.
5. Si querés, escribí unas **Observaciones** (hasta 200 caracteres).
6. Mirá el neto estimado debajo del formulario y tocá **Agregar**.
Resultado: la confirmación dice "Movimiento guardado. Neto $ X, con retención Y%." y el movimiento aparece en la tabla con origen **Manual**.
:::

El neto estimado del formulario usa la tasa de hoy. El que se guarda se calcula con la vigente a la fecha de acreditación, y es el que dice la confirmación.

Si **Nuevo movimiento** está gris, no hay ninguna procesadora activa con alícuotas vigentes: al pasar el mouse lo dice. Se arregla en Parámetros › Cob. Electrónicos.

Una fecha de hoy o anterior se puede cargar, pero ese movimiento nace **ya acreditado**: no va al tablero y no se ve en la tabla salvo que prendas **Ver también las ya acreditadas**.

### Importar la planilla de la procesadora

:::pasos
1. Tocá **Importar planilla**.
2. La primera vez, tocá **Descargar plantilla (Excel)**. Trae dos filas de ejemplo: borralas y pegá las acreditaciones.
3. En **Archivo (.xlsx o .xls)**, elegí el Excel.
4. Si sabés qué período exportaste, completá **El archivo cubre desde** y **hasta**. Es opcional.
5. Tocá **Ver diferencias**. Todavía no se guarda nada.
6. Leé el resumen, las filas que cambian y, si hay, la tabla **Cargados que el archivo no trae**.
7. Si hay que dar de baja los que el archivo no trae, tildá **Dar de baja los N que el archivo no trae**.
8. Tocá **Confirmar importación**.
Resultado: la confirmación dice "Importación aplicada: N alta(s), N cambio(s) y N baja(s)." (las bajas, sólo si hubo), el panel se cierra y la tabla se recarga.
:::

Lo que hay que saber de cada paso está en [La importación, en detalle](ayuda:cob_electronicos#la-importacion-en-detalle), al final de esta sección.

### Corregir un movimiento

:::pasos
1. Tocá el lápiz de la fila.
2. Cambiá el **Importe bruto**, la fecha o las observaciones (van en la columna **Origen**).
3. Tocá el tilde para guardar, o la cruz para cancelar.
Resultado: la confirmación dice "Movimiento actualizado. Neto $ X, recalculado con retención Y%." y el ícono de **Origen** pasa a decir quién lo cambió y cuándo.
:::

La tasa y el neto no se editan: se recalculan al guardar, con la alícuota vigente a la fecha nueva. **La procesadora no se cambia**: si está mal, dá de baja el movimiento y cargalo de nuevo.

Se puede corregir un movimiento de una procesadora inhabilitada, y también uno ya acreditado, si lo traés con el interruptor. Si le ponés fecha de hoy o anterior, pasa a **ya acreditada** y deja de verse con los filtros de siempre.

### Dar de baja un movimiento

:::pasos
1. Tocá el botón rojo de la fila.
2. Confirmá: "¿Dar de baja el movimiento de X del dd/mm/aaaa por $ B brutos? No se borra: queda inhabilitado y sale del tablero."
Resultado: la confirmación dice "Movimiento dado de baja." y la fila sale de la tabla y del tablero.
:::

La baja no se deshace desde la pantalla: si fue un error, cargalo de nuevo.

### Ver lo que ya se acreditó

:::pasos
1. Prendé **Ver también las ya acreditadas**.
2. Si querés acotar, completá **Acreditación desde** y **hasta** y tocá **Filtrar**.
Resultado: aparecen en gris, con la marca **ya acreditada**, y suman en las tarjetas y los totales de la pantalla. En el tablero siguen sin sumar.
:::

**Limpiar** vuelve a la vista de siempre: desde mañana, con el interruptor apagado.

### Dar de alta una procesadora nueva

:::pasos
1. Andá a **Parámetros › Cob. Electrónicos**.
2. Tocá **Agregar procesadora**, escribí la **Razón social** y tocá **Agregar**. Entra inactiva.
3. En **Alícuotas por procesadora**, tocá **Cargar alícuota** y cargá cada concepto con su **Vigencia desde**.
4. Volvé a la fila de la procesadora y prendé el interruptor **Activa**.
Resultado: la notificación dice ""X" activada: ya se puede elegir al cargar un movimiento." y la procesadora aparece en **Nuevo movimiento** y en la importación.
:::

En la planilla, la columna **PROCESADORA** tiene que traer la razón social tal como quedó en Parámetros (no importan mayúsculas, acentos ni espacios de más).

### Cambiar una alícuota

:::pasos
1. En **Parámetros › Cob. Electrónicos**, tocá el lápiz de la alícuota en **Alícuotas por procesadora**.
2. Escribí el porcentaje nuevo en **Alícuota** (por ejemplo `2,5`) y la **Vigencia desde**.
3. Mirá la **Tasa total resultante** y tocá **Guardar vigencia**.
Resultado: la alícuota nueva queda vigente desde esa fecha, la anterior pasa a **histórica**, y un panel dice qué movimientos pendientes se recalcularon y con qué diferencia de neto.
:::

### La importación, en detalle

Lo que sigue explica el archivo, la comparación y las bajas de la importación.

### El archivo

- **Se lee la primera hoja** del Excel (.xlsx o .xls), hasta 4 MB.
- **La primera fila con algo escrito es el encabezado**, y abajo van los datos. Si la planilla de la procesadora trae un título o renglones de texto arriba de los títulos de las columnas, hay que borrarlos.
- **Las columnas se reconocen por el título**, sin importar el orden, las mayúsculas, los acentos ni los espacios. Las columnas de más se ignoran, también una de neto: el neto lo calcula la app.

| Columna | ¿Obligatoria? | También se acepta como |
| --- | --- | --- |
| **PROCESADORA** | Sí | RAZON_SOCIAL, RAZON_SOC, RAZONSOCIAL |
| **IMPORTE_BRUTO** | Sí | IMPORTE, BRUTO, IMPORTEBRUTO |
| **FECHA_ACREDITACION** | Sí | FECHA, COBRO, FECHA_COBRO, FECHAACREDITACION |
| **ID_EXTERNO** (el número de liquidación) | No | LIQUIDACION, ID_LIQUIDACION, IDEXTERNO, NRO_LIQUIDACION |
| **OBSERVACIONES** | No | OBSERVACION, NOTAS, COMENTARIOS |

Las fechas pueden venir como fecha de Excel o como texto, día primero (dd/mm/aaaa) o aaaa-mm-dd. No se corren: la fecha que dice la celda es la que se carga.

### Ver diferencias

**Ver diferencias** compara el archivo con lo cargado y no escribe nada. Arriba dice el nombre del archivo, cuántas filas se leyeron y **"acreditaciones del dd/mm/aaaa al dd/mm/aaaa"**. Ese rango es el de las filas **que se van a importar**: no cuenta las ya acreditadas ni las que tienen problemas. Por eso puede empezar después que el período del archivo.

:::ejemplo ilustrativo
Hoy es 10 de marzo. La procesadora manda una liquidación del **9 al 20 de marzo**: 12 acreditaciones de **$ 1.000.000** brutos, una por día, **$ 12.000.000** en total.

- Las del 9 y el 10 son de hoy o antes: el contador dice **2 ya acreditadas**.
- Las otras 10 se importan. El título dice **acreditaciones del 11/03 al 20/03** (con el año).
- Después de confirmar, la tarjeta **Importe Bruto** muestra **$ 10.000.000**, no $ 12.000.000. Con una tasa de 3,1 %, **Importe Neto** muestra **$ 9.690.000**.
:::

Después vienen los contadores, sólo los que no son cero:

| Contador | Qué quiere decir |
| --- | --- |
| **nuevas** | No estaban cargadas: se agregan. |
| **con cambios** | Ya estaban, con otro importe bruto o en otra fecha: se actualizan. |
| **ya cargadas igual** | Ya estaban, iguales: no se tocan. |
| **ya acreditadas** | Tienen fecha de hoy o anterior: no se importan, porque esa plata ya está en el saldo bancario. |
| **ya no vienen** | Están cargadas y el archivo no las trae. Ver *Lo que el archivo no trae*. |
| **con problemas** | Tienen algo mal. Con una sola, no se importa nada. |

Si hay nuevas o cambios, una línea dice **Neto que se agrega** y la **diferencia de neto en los cambios**. Abajo, los avisos de la importación y una tabla con las filas **nueva**, **cambia** y **problema**: **Línea** del Excel, **Procesadora**, **Importe bruto**, **Acreditación**, **Neto** (en un cambio, también el neto de antes y la diferencia) y **Qué pasa**, con el motivo.

**Confirmar importación** queda gris con "Corregí las filas con problemas y volvé a subir el archivo." o "No hay nada que aplicar.".

Al confirmar, la app vuelve a comparar contra lo que hay cargado en ese momento y aplica todo junto, o nada.

### Cómo reconoce un movimiento ya cargado

- **Con número de liquidación** (**ID_EXTERNO**), por procesadora y número. Si la fecha cambió, es el mismo movimiento con fecha nueva: sale **cambia**.
- **Sin número de liquidación**, por procesadora y fecha. Si la procesadora reprogramó una acreditación, la fecha nueva sale **nueva** y la vieja sale entre las que **ya no vienen** (si cae dentro del período del archivo).
- **Dos filas del archivo con la misma procesadora y la misma fecha, sin número de liquidación, son un problema**: no hay forma de saber cuál es cuál. Completá **ID_EXTERNO** en las dos.

Un cambio actualiza el importe bruto, la fecha, la tasa y el neto, y deja el origen en **Archivo** con el nombre del archivo y el número de liquidación (vacío, si la fila no lo trae). Las observaciones del movimiento no se tocan.

### Lo que el archivo no trae

La tabla **Cargados que el archivo no trae** lista los movimientos que siguen cargados y que el archivo ya no informa. Sólo entran:

- de las procesadoras que vienen en el archivo;
- con fecha desde mañana;
- y con fecha dentro del período del archivo.

**El período** es el que completaste en **El archivo cubre desde / hasta**. Si no lo completaste, se toma de la primera y la última fecha que se importan, y el panel lo dice: "Se buscaron bajas entre el … y el … (inferido de las fechas del archivo; si la procesadora dio de baja la primera o la última acreditación del período, completá desde/hasta para poder verla)."

**La baja no es automática.** Hay **una sola casilla para todos**: **Dar de baja los N que el archivo no trae**. No se puede elegir de a uno. Si la tildás, al confirmar pregunta: "Se van a dar de baja N movimiento(s) que el archivo no trae. No se borran: quedan inhabilitados y salen del tablero. Si el archivo era parcial, cancelá y volvé a subirlo completo." Si no la tildás, esos movimientos quedan como estaban.

Si querés dar de baja sólo algunos, no tildes la casilla y dalos de baja de a uno desde la tabla.

## Parámetros

Se cambian en **Parámetros › Cob. Electrónicos**, con su propio permiso de edición. Ver [permisos y parámetros](ayuda:general_permisos_parametros#ver-y-editar-dos-permisos-por-pestana).

| Parámetro | Dónde se cambia | Valor por defecto | Qué cambia |
| --- | --- | --- | --- |
| Razón social | Cob. Electrónicos › Procesadoras | La instalación cargó Payway y Mercado Pago | El nombre en el alta, en la tabla y el que tiene que traer la planilla. Se guarda con **Guardar procesadoras** |
| Activa | Cob. Electrónicos › Procesadoras | Una procesadora nueva entra inactiva | Sólo las activas se pueden elegir al cargar o importar. Inhabilitarla no toca lo ya cargado, que se sigue sumando y se puede corregir. Se guarda al tocar el interruptor |
| Concepto, Alícuota (%) y Vigencia desde | Cob. Electrónicos › Alícuotas por procesadora | IIBB 2,5 % y SICREB 0,6 %, desde el día de la instalación | La tasa de los movimientos con fecha desde esa vigencia. Recalcula los pendientes |
| Columnas diarias y Columnas mensuales | Parámetros › Calendario | 28 y 12 | Hasta dónde llega el horizonte: lo que cae después es **posterior al horizonte** |

El cronograma de pagos, la inflación, el dólar y los feriados no cambian nada en esta pestaña.

### Las procesadoras

La columna **Tasa vigente hoy** suma las alícuotas de hoy y las desglosa (por ejemplo "IIBB 2,5% + SICREB 0,6%"); sin alícuotas dice **sin alícuotas**. Una procesadora no se puede activar sin al menos una alícuota vigente: el interruptor queda bloqueado, con el rótulo **Inactiva · sin alícuota**. Las procesadoras no se borran, se inhabilitan.

Si editaste nombres sin guardar y tocás un interruptor, avisa "Guardá primero los nombres editados con "Guardar procesadoras"." y no cambia nada.

### Las alícuotas

- **Guardar una alícuota siempre agrega una vigencia nueva.** La anterior queda como **histórica**: es la que explica la tasa de los movimientos de ese período. El estado de cada fila es **vigente**, **histórica** o **dada de baja**.
- **Antes de guardar**, el formulario muestra la **Tasa total resultante** y qué porcentaje del bruto va a acreditar un movimiento nuevo. Si la suma llega a 100 %, el botón se bloquea.
- **Al guardar o dar de baja una alícuota se recalculan los movimientos pendientes** de esa procesadora: los que tienen fecha de acreditación **desde mañana**. Los de hoy y anteriores conservan su tasa. Un panel muestra cada movimiento recalculado (**Procesadora**, **Acreditación**, **Bruto**, **Tasa** antes y después, **Neto** antes y después, **Diferencia**) o dice "No cambió el importe neto de ningún movimiento pendiente.". Los cambios quedan firmados por quien guardó la alícuota.
- **No se puede dar de baja la última alícuota vigente de una procesadora activa.** Primero hay que inhabilitar la procesadora.

## Avisos y qué hacer

Los avisos se ven arriba de la pestaña, con los que el tablero dice de ella. Algunos textos del tablero se escriben sin tildes. Ver [cómo se leen los avisos](ayuda:general_avisos).

:::aviso atencion
texto: Todavía no hay ninguna procesadora cargada, así que la fila del tablero va en cero y no se puede dar de alta ningún movimiento. Cargalas en Parámetros → Cob. Electrónicos.
significa: No hay ninguna procesadora en Parámetros. Sin procesadora no se carga nada.
corrige: Dá de alta la procesadora en Parámetros › Cob. Electrónicos, cargale sus alícuotas y activala.
:::

:::aviso atencion
texto: Ninguna procesadora está activa, así que no se puede dar de alta ningún movimiento. Una procesadora se activa desde Parámetros → Cob. Electrónicos una vez que tiene al menos una alícuota vigente.
significa: Hay procesadoras, pero todas inhabilitadas. **Nuevo movimiento** queda gris y la importación rechaza las filas.
corrige: En Parámetros › Cob. Electrónicos, prendé **Activa** en la que corresponda (si no tiene alícuota, cargale una antes).
:::

:::aviso atencion
texto: Estas procesadoras están activas pero no tienen alícuotas vigentes a hoy: X, Y. Sus movimientos no van a poder calcular el importe neto hasta que se les cargue una.
significa: Esas procesadoras no aparecen en el alta, y un movimiento suyo con fecha de hoy en adelante no se puede guardar.
corrige: Cargales una alícuota en Parámetros › Cob. Electrónicos, con vigencia de hoy o anterior.
:::

:::aviso info
texto: Hay $ X netos de acreditaciones con fecha posterior al final del horizonte (la más lejana, del dd/mm/aaaa): se ven en la tabla de la pestaña, pero el tablero todavía no los muestra porque su fecha no tiene columna.
significa: Hay acreditaciones informadas para después del último mes del tablero. Están en la tarjeta **Posterior al Horizonte** y marcadas en rojo en la tabla.
corrige: Nada. Entran solas cuando el horizonte llegue a su fecha. Si las querés ver antes, subí **Columnas mensuales** en Parámetros › Calendario.
:::

:::aviso info
texto: Fila: $ X con fecha fuera del horizonte proyectado no se muestran en el tablero.
significa: Lo mismo que el aviso anterior, dicho por el tablero con el nombre de su fila.
corrige: Nada, igual que el anterior.
:::

:::aviso atencion
texto: Hay más de un movimiento para la misma procesadora y la misma fecha (X el dd/mm/aaaa; …). Puede ser correcto —dos liquidaciones el mismo día— pero también es lo que se ve cuando una carga quedó pegada dos veces.
significa: Hay dos o más movimientos de la misma procesadora el mismo día. La app no los bloquea: puede haber dos liquidaciones en un día. Sólo mira los movimientos que muestra la tabla con los filtros puestos.
corrige: Revisá esos movimientos. Si uno está repetido, dalo de baja.
:::

:::aviso atencion
texto: Hay N procesadora(s) configuradas pero ningun movimiento cargado todavia, asi que la fila va en cero. Cargalos en la pestana Cob. Electronicos.
significa: Hay procesadoras, pero ningún movimiento cargado. La fila del tablero en cero no quiere decir que no haya cobranzas.
corrige: Cargá las acreditaciones a mano o importá la planilla de la procesadora.
:::

:::aviso info
texto: Los N movimientos cargados tienen fecha de acreditacion de hoy o anterior, asi que ya estan informados en el saldo bancario y la fila va en cero. No hay acreditaciones pendientes cargadas.
significa: Todo lo cargado ya se acreditó. La fila en cero es correcta mientras no haya acreditaciones nuevas.
corrige: Si la procesadora ya informó acreditaciones desde mañana, cargalas o importá la planilla.
:::

:::aviso info
texto: Los N movimientos pendientes tienen fecha posterior al final del horizonte, asi que la fila va en cero. Se ven igual en la pestana Cob. Electronicos.
significa: Hay acreditaciones pendientes, pero todas después del final del horizonte.
corrige: Nada. Si las querés ver en el tablero, subí **Columnas mensuales** en Parámetros › Calendario.
:::

:::aviso info
texto: Los movimientos cargados ya se acreditaron, asi que estan informados en el saldo bancario y la fila va en cero. No hay acreditaciones pendientes cargadas.
significa: Lo mismo que el aviso de los N movimientos de hoy o anteriores.
corrige: Igual que ese.
:::

:::aviso atencion
texto: Hay movimientos de X cuya procesadora no tiene alicuotas vigentes a su fecha de acreditacion. Suman con el neto que ya tenian guardado, pero no se van a poder cargar movimientos nuevos hasta que se le cargue una alicuota.
significa: A esa procesadora le dieron de baja las alícuotas que regían en la fecha de esos movimientos. El tablero los suma igual, con el neto que tenían.
corrige: Cargale a la procesadora una alícuota con vigencia igual o anterior a esas fechas en Parámetros › Cob. Electrónicos.
:::

### Los avisos de la importación

Salen en el panel de diferencias, después de **Ver diferencias**.

:::aviso atencion
texto: Hay N fila(s) con problemas, así que no se importa nada hasta corregirlas. Un archivo importado a medias deja un estado que la próxima importación no puede explicar.
significa: Alguna fila tiene un error. El motivo de cada una está en la columna **Qué pasa**.
corrige: Corregí esas filas en el Excel y volvé a tocar **Ver diferencias**.
:::

:::aviso info
texto: N fila(s) del archivo ya se acreditaron y no se importan: esa plata ya está informada en el saldo bancario de la pestaña Saldos.
significa: Esas filas tienen fecha de hoy o anterior. No son un error: no se cargan.
corrige: Nada.
:::

:::aviso atencion
texto: Hay N movimiento(s) cargados que el archivo no trae. Se pueden dar de baja, pero hay que marcarlo expresamente: si el archivo era parcial, esas acreditaciones siguen siendo válidas.
significa: Están en la tabla **Cargados que el archivo no trae**.
corrige: Si el archivo es completo para ese período, tildá **Dar de baja los N que el archivo no trae**. Si era parcial, no la tildes.
:::

:::aviso info
texto: El archivo no cambia nada de lo que ya está cargado.
significa: Todo lo que trae ya está cargado igual, o ya se acreditó.
corrige: Nada. **Confirmar importación** queda gris.
:::

Los motivos de la columna **Qué pasa**:

| Motivo | Qué hacer |
| --- | --- |
| "No estaba cargada." | Nada: se agrega. |
| "Ya estaba cargado igual." | Nada. No se muestra en la tabla, sólo en el contador. |
| "El importe bruto pasa de $ A a $ B y la fecha pasa del dd/mm/aaaa al dd/mm/aaaa." (o una sola de las dos cosas) | Nada: se actualiza. |
| "Falta la procesadora." | Completá la columna **PROCESADORA**. |
| "La procesadora "X" no está cargada. Dala de alta en Parámetros → Cob. Electrónicos o corregí el nombre en el archivo." | El nombre no coincide con ninguna procesadora de Parámetros. |
| "La procesadora "X" está inhabilitada, así que no admite movimientos nuevos." | Activala en Parámetros, o sacá esas filas. |
| "El importe bruto "V" no es un número." / "El importe bruto tiene que ser mayor a cero." | Corregí el importe. |
| "La fecha de acreditación "V" no se entiende. Usá dd/mm/aaaa o aaaa-mm-dd." | Corregí la fecha. |
| "El ID_EXTERNO no puede superar los 60 caracteres." | Acortá el número de liquidación. |
| ""X" no tiene ninguna alícuota vigente al dd/mm/aaaa, así que no se puede calcular el importe neto." | Cargale una alícuota con vigencia igual o anterior a esa fecha. |
| "Las alícuotas vigentes de "X" al dd/mm/aaaa suman P%: el neto saldría cero o negativo." | Revisá las alícuotas en Parámetros. |
| "Esta fila repite la clave de la línea N (misma procesadora y misma fecha). Si son dos liquidaciones distintas del mismo día, completá ID_EXTERNO en las dos para poder diferenciarlas." | Completá **ID_EXTERNO**, o borrá la fila repetida. |

Una fila ya acreditada no se lista en la tabla, pero su motivo es "Se acredita el dd/mm/aaaa, antes del dd/mm/aaaa (mañana): ya está informada en el saldo bancario, así que no se importa.". Un movimiento entre los que el archivo no trae: "Está cargado pero el archivo no lo trae, y su fecha cae dentro del período que el archivo cubre (dd/mm/aaaa al dd/mm/aaaa).".

Si el archivo no se puede leer, el error sale en rojo en el mismo panel. Los más comunes: "Al archivo le faltan columnas obligatorias: … Descargá la plantilla y usá sus encabezados. Se encontraron: …." (casi siempre, un título arriba del encabezado), "El archivo no tiene ninguna fila de datos. La primera fila es el encabezado y abajo van los datos.", que el archivo no es un Excel, que la primera hoja está vacía, o "La planilla pesa más de 4 MB. Una planilla de importación no llega a eso: revisá que sea el archivo correcto.".

### En Parámetros › Cob. Electrónicos

Esa sección muestra también los tres avisos de configuración de arriba (sin procesadoras, ninguna activa, activas sin alícuotas). Y al guardar una alícuota, en el panel del recálculo:

:::aviso atencion
texto: Hay movimientos pendientes de X que no se pudieron recalcular porque esa procesadora no tiene alícuotas vigentes utilizables a su fecha de acreditación. Conservan la tasa con la que se cargaron.
significa: Con lo que quedó vigente, esos movimientos no tienen tasa: siguen con la de antes.
corrige: Cargá una alícuota con vigencia igual o anterior a la fecha de esos movimientos.
:::

### Los mensajes al guardar

No son avisos: aparecen un momento después de cada acción.

| Mensaje | Cuándo |
| --- | --- |
| "Movimiento guardado. Neto $ X, con retención Y%." | Se cargó un movimiento a mano. No dice si ya había otro de la misma procesadora ese día: eso lo dice el aviso de movimientos repetidos, en el panel de avisos. |
| "Movimiento actualizado. Neto $ X, recalculado con retención Y%." | Se corrigió un movimiento. |
| "Movimiento dado de baja." | Se dio de baja un movimiento. |
| "Importación aplicada: N alta(s), N cambio(s) y N baja(s)." | Se confirmó una importación. Las bajas se nombran sólo si hubo. |
| "No se pudo guardar el movimiento: Error: …" | El alta o la corrección se rechazó. Después de "Error:" dice por qué: la procesadora inhabilitada, sin alícuota vigente a esa fecha, un importe en cero. |
| "No se pudo importar: Error: …" | La confirmación se rechazó: por ejemplo, filas con problemas o nada que cambiar. No se escribió nada. |
| "No se pudo dar de baja el movimiento: Error: …" | La baja se rechazó: por ejemplo, ya estaba dado de baja. |

En el formulario de alta, antes de enviar: "Elegí una procesadora.", "El importe bruto tiene que ser mayor a cero." y "La fecha de acreditación es obligatoria: sin ella el movimiento no se puede ubicar en el tablero.".

### Avisos para Sistemas

Los de instalación y los de lectura: falta una tabla, falta correr un script o no se pudo leer algo. No los corrige quien usa la pantalla: hay que avisar a sistemas, con el texto del aviso.

:::aviso critico
texto: Todavía no existen las tablas del módulo Cob. Electrónicos, así que la fila del tablero va en cero. Corré sql/cashflow_cob_electronicos.sql contra la base central.
significa: El módulo no está instalado. La pestaña no muestra nada y no deja cargar.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: No se pudo verificar la configuración del módulo: …
significa: Falló la lectura de las procesadoras o las alícuotas para armar los avisos.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: No se pudo leer el horizonte del tablero (…), así que la marca de qué movimientos entran al tablero puede no coincidir con el tablero.
significa: La pestaña usa un horizonte de respaldo de 28 días y 12 meses. La marca **posterior al horizonte** puede no ser la del tablero.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: No se pudieron leer los movimientos: …
significa: La tabla queda vacía aunque haya movimientos cargados.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

:::aviso critico
texto: No se pudieron leer las alicuotas (…). Los movimientos suman igual, con el neto que ya tenian guardado.
significa: El tablero no pudo verificar las alícuotas. Los importes salen igual.
corrige: Avisá a sistemas.
:::

:::aviso critico
texto: No se pudieron cargar las acreditaciones: …
significa: La pestaña no pudo cargar. Sale en un cartel.
corrige: Tocá **Actualizar**. Si sigue, pasale a sistemas el texto del cartel.
:::

:::aviso atencion
texto: No se pudieron traer los avisos del tablero para esta pestaña.
significa: Se ven los avisos de la pestaña, pero no los que el tablero dice de ella.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

En Parámetros › Cob. Electrónicos:

:::aviso atencion
texto: No se pudieron leer los parámetros de Cob. Electrónicos: …
significa: La sección no pudo cargar las procesadoras o las alícuotas.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

Si el módulo no está instalado, cualquier carga, importación o cambio de parámetros se rechaza con "No existen las tablas del módulo Cob. Electrónicos. Corré sql/cashflow_cob_electronicos.sql.".

## Preguntas frecuentes

:::pregunta ¿Por qué la comparación dice que las acreditaciones empiezan después que el período del archivo?
Porque lo que se acredita hoy o antes no se importa. Esa plata ya está —o va a estar al cierre del día— en el saldo del banco, que informa la pestaña Saldos. Cargarla acá la contaría dos veces. Esas filas se cuentan en el contador **ya acreditadas**.

El rango "acreditaciones del … al …" de **Ver diferencias** es el de las filas que **sí** se van a cargar, así que arranca mañana (o en la primera fecha del archivo posterior a hoy). No se pierde ningún día ni se corre ninguna fecha: cada fila entra con la fecha que trae.

Por lo mismo, **los totales de la pestaña no son los de la liquidación** cuando la liquidación incluye hoy o días pasados: son los de lo pendiente. Prender **Ver también las ya acreditadas** tampoco los completa, porque esas filas del archivo nunca se cargaron. Hay un ejemplo con números en [Ver diferencias](ayuda:cob_electronicos#ver-diferencias).
:::

:::pregunta ¿Por qué no aparece lo que se acredita hoy?
Porque ya está, o va a estar hoy, en el saldo bancario de la pestaña Saldos, que es la primera columna del tablero. Si también se contara acá, el tablero lo sumaría dos veces. Lo pendiente empieza mañana.

Un movimiento de hoy cargado a mano se guarda igual, pero nace **ya acreditado**: se ve sólo con **Ver también las ya acreditadas**.
:::

:::pregunta ¿Las ya acreditadas suman cuando prendo el interruptor?
En la pantalla, sí: entran en las tarjetas **Importe Bruto**, **Retenciones** e **Importe Neto**, en el pie **TOTALES**, en **Totales por procesadora** y en los cuadros por día y por mes. En el tablero, no: nunca suman ahí. Se ven en gris con la marca **ya acreditada**.

Para ver sólo lo que va al tablero, dejá el interruptor apagado y **Acreditación desde** vacío.
:::

:::pregunta ¿Tengo que dejar el Excel de la procesadora igual a la plantilla?
No. No hace falta el mismo orden de columnas ni los títulos exactos: se aceptan los de la plantilla y sus equivalentes (RAZON_SOC, Importe, Bruto, Fecha, Cobro, Liquidación, Notas, entre otros), sin importar mayúsculas ni acentos. Las columnas de más se ignoran.

Lo que sí hace falta: que los datos estén en la **primera hoja**, que la **primera fila con algo escrito sea la de los títulos** (sin un título de reporte arriba) y que estén las tres columnas obligatorias: procesadora, importe bruto y fecha de acreditación. La tabla completa de títulos está en [El archivo](ayuda:cob_electronicos#el-archivo).
:::

:::pregunta ¿Qué pasa si subo el mismo archivo otra vez?
Nada se duplica. Las filas salen **ya cargadas igual**, el aviso dice "El archivo no cambia nada de lo que ya está cargado." y **Confirmar importación** queda gris con "No hay nada que aplicar.".

Dos excepciones: si en el medio pasó algún día, las filas que ahora son de hoy o antes salen **ya acreditadas**; y si la vez anterior quedaron movimientos que el archivo no trae sin dar de baja, vuelven a aparecer y el botón se habilita.
:::

:::pregunta ¿Qué pasa si la procesadora cambia la fecha de una acreditación?
Depende de si el archivo trae el número de liquidación (**ID_EXTERNO**):

- **Con número**: la app reconoce el movimiento y lo muestra como **cambia**, con la fecha vieja y la nueva.
- **Sin número**: la fecha nueva sale como **nueva**, y la vieja como uno de los **Cargados que el archivo no trae** (si cae dentro del período del archivo). Para no quedar con las dos, tildá **Dar de baja los N que el archivo no trae** o dala de baja a mano.

Por eso conviene que el archivo traiga el número de liquidación.
:::

:::pregunta ¿Puedo dar de baja sólo algunos de los que el archivo no trae?
Desde la importación, no: la casilla **Dar de baja los N que el archivo no trae** es una sola para todos. Si querés bajar sólo algunos, confirmá sin tildarla y dalos de baja de a uno desde la tabla, con el botón rojo de su fila.

Si sólo hay bajas para aplicar y confirmás sin tildar la casilla, no cambia nada: la confirmación dice "Importación aplicada: 0 alta(s), 0 cambio(s).".
:::

:::pregunta ¿Me avisa si cargo dos veces la misma acreditación?
No la bloquea, porque una procesadora puede liquidar dos veces el mismo día. Al guardar, la confirmación no lo dice. El aviso aparece en el panel de avisos de la pestaña: "Hay más de un movimiento para la misma procesadora y la misma fecha (…)". Sólo mira lo que muestra la tabla, así que dos ya acreditadas se avisan sólo con el interruptor prendido.

En la importación es distinto: dos filas de la misma procesadora y la misma fecha sin número de liquidación son un problema, y no se importa nada hasta corregirlas.
:::

:::pregunta ¿Por qué el total de la pestaña no es el del tablero?
Con los filtros de siempre (interruptor apagado, sin fechas), la tarjeta **Importe Neto** es la fila del tablero más la tarjeta **Posterior al Horizonte**: lo que cae después del último mes no tiene columna.

Si filtrás por procesadora o fechas, o prendés las ya acreditadas, la pestaña suma otra cosa que el tablero.
:::

:::pregunta ¿Qué pasa con lo ya cargado si cambio una alícuota?
Los movimientos pendientes de esa procesadora (fecha desde mañana) se recalculan con las alícuotas que quedaron vigentes a su fecha, y un panel muestra cuáles cambiaron y en cuánto. Los de hoy y anteriores no se tocan: conservan la tasa con la que entraron.

Una alícuota con vigencia futura sólo cambia los movimientos con fecha desde esa vigencia.
:::

:::pregunta ¿Puedo agregar otra procesadora?
Sí, en Parámetros › Cob. Electrónicos: **Agregar procesadora**, cargarle sus alícuotas y activarla. Ver la tarea *Dar de alta una procesadora nueva*. No hace falta ningún cambio de sistemas: las procesadoras son las que estén cargadas ahí.
:::

:::pregunta ¿Esta fila se descuenta de la cobranza de Ventas?
No. La fila de esta pestaña y la de cobros sobre ventas estimadas de Ventas no se cruzan, por decisión del negocio: no hay descuento ni prorrateo entre las dos.
:::

:::pregunta ¿Por qué no puedo cambiar la procesadora de un movimiento?
Porque la tasa sale de las alícuotas de la procesadora. Si la procesadora está mal, dá de baja el movimiento y cargalo de nuevo con la correcta.
:::

## Glosario

| Término | Qué quiere decir |
| --- | --- |
| Procesadora | Quien deposita la cobranza electrónica en el banco. Son las cargadas en Parámetros › Cob. Electrónicos |
| Acreditación | Un depósito que la procesadora informa, con su importe y su fecha |
| Importe bruto | Lo que informa la procesadora, antes de las retenciones |
| Alícuota | El porcentaje que retiene la procesadora por un concepto (IIBB, SICREB o el que se cargue) |
| Vigencia desde | El día desde el que rige una alícuota. La anterior del mismo concepto queda como histórica |
| Tasa aplicada | La suma de las alícuotas vigentes a la fecha de acreditación. Queda guardada con el movimiento |
| Retenciones | Bruto menos neto: lo que nunca llega al banco |
| Importe neto | Bruto × (1 − tasa): lo que entra a la cuenta y lo único que va al tablero |
| Pendiente | Una acreditación con fecha desde mañana |
| Ya acreditada | Una acreditación con fecha de hoy o anterior. La informa el saldo bancario de Saldos |
| Posterior al horizonte | Una acreditación con fecha después de la última columna del tablero |
| Horizonte | El período que proyecta el tablero: las columnas diarias más los meses |
| ID_EXTERNO | El número de liquidación de la procesadora. Identifica un movimiento al importar |
| Origen | Cómo entró el movimiento: Manual (cargado a mano) o Archivo (importado) |
| Período del archivo | Las fechas que cubre la planilla. Define dónde se buscan los movimientos que el archivo no trae |
