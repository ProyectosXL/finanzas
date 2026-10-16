# Echeqs — los cheques depositados, leídos de Tango

*Ingresos › Echeqs › Cheques en Cartera* y *Parámetros › Cheques*. Lo demás de Echeqs —las dos sub-pestañas, la exclusión de cartera y la venta cobrada anticipada— está en `README-cashflow.md` (*Excluir un cheque que no se va a poder cobrar*) y en `README-ventas.md` (el neteo de cheques adelantados).

## La idea en una línea

Tesorería deposita los echeqs en el banco y **registra el depósito en Tango en el mismo momento**, con una boleta de depósito (**BDE**): lo hace así a propósito, para que nadie le asigne ese cheque a un pago. El cheque pasa a `ESTADO = 'A'` y sale de cartera. El cashflow **lee ese depósito de Tango** y lo proyecta en *Echeqs depositados* **por el neto**, el día que acredita: **la fecha de la boleta más el plazo hábil de Parámetros** (hoy 2).

```
acreditación = N-ésimo día hábil después de FECHA_SAL      (N = el plazo de Parámetros)
neto         = bruto × (1 − tasa)
tasa         = suma de las alícuotas de los cheques vigentes a la fecha de ACREDITACIÓN
```

Deja de proyectarse solo, **sin aviso**: el día que acredita —la plata ya está en el saldo de Interbanking— o cuando alguien lo marca **ya acreditó**.

**Por qué cambió.** Antes el depósito se marcaba en el cashflow (botón *Depositar*) mientras el cheque seguía en cartera en Tango. Como Tesorería lo registra en Tango primero, el cheque salía de cartera antes de que nadie lo marcara, y **durante las 48 hs hábiles hasta que acreditaba esa plata no estaba en ninguna fila del tablero**: ni en cartera, ni en depositados, ni todavía en el saldo bancario. El botón *Depositar* se eliminó; ver *Lo que se fue*.

---

## Para pasar a producción: los scripts, en orden

Contra `central`, reejecutables y sin borrar nada.

| # | Script | Qué hace | Si no se corre |
| --- | --- | --- | --- |
| 1 | `sql/cashflow_echeqs_deposito.sql` | Crea `RO_T_CASHFLOW_ECHEQ_ALICUOTA` (las alícuotas de los cheques, con vigencias) y siembra el plazo `echeqs_dias_acreditacion_deposito` en **2** sin pisarlo si ya existe. **No siembra alícuotas.** Ya no crea `RO_T_CASHFLOW_ECHEQ_DEPOSITO` | Los depositados se listan **sin neto y sin proyectarse**, con un aviso crítico que nombra el script. *Parámetros › Cheques* avisa |
| 2 | `sql/cashflow_estructura_echeqs_depositados.sql` | Crea la fila **Echeqs depositados** (`ECHEQS_DEPOSITADOS`) en *Disponibilidades*, `ORDEN = 25`, `ECHEQS → DEPOSITADOS`, `INGRESO`, `COMPUTA = 1`. `MERGE` sobre `CODIGO`: no pisa nombre ni orden editados | Los depositados que se proyectan no entran en ninguna fila: la pestaña lo avisa como **crítico** |
| 3 | `sql/cashflow_echeqs_acreditado.sql` | Crea `RO_T_CASHFLOW_ECHEQ_ACREDITADO`: las marcas **ya acreditó**, con su origen e historial | El botón *Ya acreditó* no se dibuja, la pestaña avisa qué script falta y los depositados **se proyectan hasta su fecha de acreditación** |

**El 1 y el 2 ya están corridos en producción** —verificado el 16/10/2026: las alícuotas (IIBB 2,5% y SICREB 0,6%), el plazo en 2 y las dos filas de `ECHEQS` activas—; volver a correr el 1 no cambia nada. **El 3 es el único nuevo**, va después de `sql/cashflow_auditoria_usuario.sql` y no depende de los otros dos.

**El código se puede publicar antes que el 3**: sin la marca, la pestaña y el tablero ya leen los depósitos de Tango.

### Después de correrlos, verificar

- *Echeqs en cartera* (`A_COBRAR`) y *Echeqs depositados* (`DEPOSITADOS`) **activas las dos**, en los órdenes 20 y 25. Son dos partes distintas: activas juntas es lo correcto.
- **Ninguna fila activa con `ECHEQS → A_COBRAR_TODO`**: es el total, incluye a las dos, y el validador lo rechaza al lado de cualquiera.
- En la pestaña Echeqs aparecen los botones *Ya acreditó* y *Deshacer ya acreditó* al seleccionar un depositado, y el aviso del script de la marca ya no está.

### Lo que mueve el tablero

Medido contra la base el **16/10/2026** con las clases reales, el criterio viejo y el nuevo uno detrás del otro sobre los mismos datos:

| Serie | Antes | Ahora |
| --- | --- | --- |
| `A_COBRAR` (*Echeqs en cartera*) | $ 1.940.142.016,30 | $ 1.940.142.016,30 |
| `DEPOSITADOS` (*Echeqs depositados*) | $ 0 | **$ 37.826.439,63**, todo el lunes 19/10 |
| `A_COBRAR_TODO` | $ 1.940.142.016,30 | $ 1.977.968.455,93 |

Los $ 37,8 M son los 5 cheques [FL] de la boleta `02005000040735` del jueves 15/10 —$ 39.036.573,41 brutos a 3,1%—, que hasta hoy no estaban en ninguna fila. Los 3 de la boleta del miércoles 14/10 ($ 24.970.612,47) acreditan hoy: se listan *acreditado (por plazo)* y no suman. *Echeqs en cartera* no cambia: esos cheques ya habían salido de la lectura de cartera el día del depósito.

---

## Qué es un depósito

Un cheque de `dbo.SBA14` es un depósito si cumple **todo** esto (`Echeqs::esDepositoTango()`, puro):

| Condición | Por qué |
| --- | --- |
| `ESTADO = 'A'` | Salió de cartera. Uno que después se rechaza o se anula pasa a `R` o `X` y deja de leerse solo |
| `T_COMP_SAL = 'BDE'` | Salió por una boleta de depósito. Un `O/P` es una orden de pago: el cheque se endosó a un proveedor, no entra al banco y no se toca |
| `CLIENTE LIKE '[FL]%'` | El universo del módulo, con la comparación binaria de la columna |
| `FECHA_SAL` real | No la centinela `1800-01-01` |
| **La cuenta que la boleta debita es un banco** | `dbo.SBA01.TIPO = 'B'`. Ver abajo |

**Se identifica por el código `BDE` y no por `ID_SBA02_SAL`.** Las dos columnas coinciden siempre —las 27.627 filas con `BDE` tienen `ID_SBA02_SAL = 11`, "BANCO: DEPOSITO" en `dbo.SBA02`—, pero el código es la misma clave con la que se lee la boleta en `dbo.SBA05` y un id interno puede cambiar entre instalaciones.

### La cuenta de la boleta, y por qué `TIPO_SAL` no decide nada

Una BDE tiene en `dbo.SBA05` **una línea al debe** contra la cuenta destino y una al haber contra **116910 CHEQUES ELECTRONICOS**. Verificado el 16/10/2026 sobre 120 días de boletas: ninguna tiene dos líneas al debe.

- **`TIPO_SAL = 'D'`** trae la cuenta también en `CTA_DESTIN`.
- **`TIPO_SAL = 'O'`** deja `CTA_DESTIN` en 0, pero su boleta debita una cuenta igual que la de un `D`. En 90 días eran la mayoría: 235 de 358. La boleta `02005000040735` de la muestra debita 110500 BCO.RIO CTA.CTE. $.

**Lo que separa los casos es la cuenta debitada**, no el tipo. BDE de [FL] en estado `A`, 90 días al 16/10/2026:

| Cuenta debitada | `SBA01.TIPO` | Cheques | Bruto | Qué es | ¿Depósito? |
| --- | --- | --- | --- | --- | --- |
| 110500 RIO, 110800 FRANCES, 110100 GALICIA, 110600 ICBC | `B` | 152 | $ 1.073 M | *"DEPOSITO ECHEQS A BANCO …"* | **Sí** |
| 110530 BCO. RIO - CTA COMITENTE 7786 | `O` | 206 | $ 677 M | *"ENDOSO A SANTANDER VALORES"*; el último, el 14/09 | No: va a la cuenta comitente, no acredita en el saldo de Interbanking |
| 100100 CAJA FABRICA | `O` | 0 en `A` | — | *"DEVOLUCION ECHEQ …"*: terminaron todos en `R` | No |

**Sin la línea de la boleta, el cheque cuenta como depósito** con un aviso de atención que nombra la boleta: es un dato que falta, no uno que diga que no entra al banco.

**Un descuento de echeqs cuenta como depósito, con la regla general.** La boleta `…040556` del 19/08 a BBVA (*"BBVA DESCUENTO DE ECHEQS"*, 44 cheques por $ 328,6 M con fecha de noviembre) es una cuenta bancaria: se proyecta a las 48 hs de la boleta, con IIBB + SICREB. El tooltip muestra las dos fechas —boleta y cheque—; si el banco acreditó antes, se marca *Ya acreditó*. Decisión de Tesorería.

**La exclusión es de cartera.** Un cheque excluido que después se deposita en Tango **se proyecta igual**: el depósito es el hecho más nuevo y dice que la plata entra. La exclusión queda en su historial, sin efecto. Si después se rechaza, pasa a `R` y sale solo.

---

## Dónde cae y por cuánto: una sola regla

`Echeqs::resolverDeposito()`, pura, decide la acreditación, la tasa, el neto y el estado. La usan la pestaña y el proveedor **por la misma lectura**, `Echeqs::getDepositadosTango()`: la pestaña y el tablero suman lo mismo, y lo fija una prueba.

| Dato | Regla |
| --- | --- |
| Acreditación | El **N-ésimo día hábil bancario posterior** a `FECHA_SAL` (`fechaAcreditacion()` → `DiasHabiles::habilesDespues()`). Con 2: lunes → miércoles; viernes, sábado o domingo → martes; un feriado en el medio lo corre un día más |
| Tasa | La suma de las alícuotas **vigentes a la fecha de acreditación** (`Retenciones::resolverTasa()`), como Cob. Electrónicos |
| Neto | `Retenciones::importeNeto()` sobre el bruto (`IMPORTE_CH`) |

**La fecha del cheque no interviene**, y es una decisión de negocio: el banco acredita a las 48 hs hábiles de recibir el depósito. Un cheque fechado dentro de veinte días depositado hoy acredita pasado mañana.

**Se calcula al leer, no se guarda.** Ningún neto ni ninguna fecha de acreditación están en una tabla. Cargar o dar de baja una alícuota, o cambiar el plazo, se ve solo en los cheques que todavía no acreditaron, en la próxima lectura. Por eso ya no existe el recálculo de pendientes.

El calendario es `RO_T_CALENDARIO`, por la única lectura del módulo (`Ventas::getDiasHabiles()`), con el respaldo de lunes a viernes y su aviso de calendario faltante.

### Cuánto tiempo se proyecta

| Caso | Estado | Qué pasa |
| --- | --- | --- |
| Acredita **de mañana en adelante** | `PROYECTA` | Entra en *Echeqs depositados* el día que acredita, por el neto |
| Acredita **hoy** | `ACREDITADO` (por plazo) | **No se proyecta**, sin aviso: ya está —o va a estar al cierre— en el saldo de Interbanking. Se lista ese día con la marca *acreditado (por plazo)* |
| Acreditó **antes de hoy** | — | Deja de leerse |
| Marcado **ya acreditó** | `ACREDITADO` (por marca) | No se proyecta aunque falten días. Se lista con la marca *acreditado* hasta su fecha de acreditación |
| Sin fecha o sin neto | `SIN_DATOS` | No se proyecta, se lista con la marca *depositado, sin proyectar* y **avisa como crítico** |
| Rechazado o anulado (`R`, `X`) | — | Ya no es `A`: deja de leerse solo |

**El corte es `Retenciones::cortePendientes()`, el mismo de Cob. Electrónicos**: si un módulo contara la acreditación de hoy y el otro no, la misma plata se contaría en una fila y no en la otra.

**Datos faltantes: null y un aviso, nunca un cero ni el bruto.**

| Falta | Aviso |
| --- | --- |
| El plazo (parámetro roto o faltante) | Crítico, con el motivo de `plazoDesdeValor()` |
| Un día hábil en el tope del calendario | Crítico: *Revisá RO_T_CALENDARIO* |
| Alícuotas vigentes a la acreditación | Crítico, diciendo dónde cargarlas |
| El calendario de un mes | Atención: la fecha se calculó con el respaldo de lunes a viernes |
| La línea de la boleta en `SBA05` | Atención: se cuenta como depósito sin poder verificar el banco |

Los avisos son **de lo que se lista**: un depósito que ya acreditó no se proyecta de todos modos, y avisar que le faltan alícuotas pediría una acción que no cambia nada.

### La lectura: sólo lo necesario

```sql
WHERE s.ESTADO = 'A' AND s.T_COMP_SAL = 'BDE' AND s.CLIENTE LIKE '[FL]%'
  AND s.FECHA_SAL >= hoy − plazo × DiasHabiles::MAX_CORRIMIENTO
```

Una boleta anterior a esa fecha ya acreditó seguro: cada día hábil del plazo puede correrse hasta `MAX_CORRIMIENTO` días (`Echeqs::rangoDepositos()`, mismo criterio que `rangoCalendarioAcreditacion()` de Saldos). El 16/10/2026 eran 225 cheques en 60 días. Sin plazo se lee un solo tramo de corrimiento, para poder avisar cuántos quedaron sin proyectar.

**Las boletas y las cuentas se cruzan en PHP**, por la regla del repo: tres lecturas chicas —los cheques de `SBA14` (con el `LEFT JOIN` de `BANCO` que ya usa la cartera, para el nombre del emisor), la línea al debe de cada boleta en `SBA05` y su cuenta en `SBA01`, y las marcas *ya acreditó*—. La consulta aplica el mismo filtro que `esDepositoTango()` como optimización; la regla que decide es la de PHP.

**La cartera volvió a ser sólo `ESTADO = 'C'`**, sin unión con ningún depósito: un cheque depositado no está en cartera en Tango. Ver `sqlCartera()`.

---

## Ya acreditó

Cuando Tesorería ve que un cheque ya acreditó antes de las 48 hs, lo marca y sale de *Echeqs depositados*. Así no se cuenta dos veces con el saldo de Interbanking, que ahora se actualiza cada hora.

- **Dónde:** en la barra de selección de *Cheques en Cartera*, **Ya acreditó** y **Deshacer ya acreditó**. Se dibujan sólo con permiso de edición de Echeqs (`['echeqs', null]`), y sólo se muestran si el script de la marca está corrido.
- **Qué se puede marcar:** sólo depositados que **todavía se proyectan**, uno o varios, **todo o nada**. Uno en cartera, uno ya marcado, uno que acredita hoy, uno sin datos o uno que no está en la lectura —endosado, rechazado, vencido— se rechazan diciendo cuál y por qué. El servidor vuelve a validar contra la lectura de la base (`Echeqs::validarSeleccionAcreditado()`).
- **El diálogo** pide confirmación, dice cuántos y por cuánto neto salen de la fila, y acepta una observación opcional.
- **Del navegador viajan sólo los ids y la observación.** El origen (`MANUAL`) y la fecha (hoy) los pone el servidor.
- **Deshacer** es baja lógica, con confirmación, todo o nada: el cheque vuelve a *Echeqs depositados* si su acreditación todavía es de mañana en adelante. Una marca de Interbanking se deshace igual que una manual.
- **El tooltip** de la marca dice quién marcó, cuándo, el origen y la observación, y las marcas deshechas con quién las deshizo.

### El modelo: `RO_T_CASHFLOW_ECHEQ_ACREDITADO`

| Columna | Qué guarda |
| --- | --- |
| `ID` | `IDENTITY`, PK. No es `ID_SBA14`: un cheque puede tener varias marcas en el tiempo |
| `ID_SBA14` | El cheque. **Sin FK contra `dbo.SBA14`**: es una tabla de Tango |
| `FECHA_ACREDITACION` | Cuándo acreditó: el día en que se marcó a mano, o la que informe el origen |
| `ORIGEN` | `MANUAL` o `INTERBANKING`, con `CHECK` |
| `REFERENCIA` | El id del movimiento en el origen. `NULL` en una marca manual |
| `OBSERVACION` | Opcional |
| `VIGENTE`, `ID_REEMPLAZA` | La marca vigente, y la que esta reemplaza (por ejemplo, Interbanking confirmando una manual) |
| Las seis de auditoría | Alta, modificación y baja |

- **Un solo vigente por cheque**: índice único filtrado `WHERE VIGENTE = 1`. PHP ya rechaza marcar uno marcado; el índice frena a dos pestañas —o a la pantalla y el proceso— al mismo tiempo.
- **Una `REFERENCIA` del origen se graba una sola vez, vigente o no**: índice único sobre `(ORIGEN, REFERENCIA) WHERE REFERENCIA IS NOT NULL`.

### Pensada para Interbanking

Más adelante, un proceso que lea la API de Interbanking va a grabar ahí las acreditaciones con `ORIGEN = 'INTERBANKING'` y su `REFERENCIA`. **La regla no depende del origen**: `resolverDeposito()` sólo pregunta si hay una marca vigente, sea de quien sea.

**El proceso futuro no puede volver a grabar una `REFERENCIA` que ya existe, aunque esté dada de baja.** Así, si alguien deshace a mano una marca de Interbanking —porque el proceso se equivocó de cheque—, el deshacer se respeta: la próxima corrida no la puede volver a poner. Lo asegura el índice único de la referencia, y lo dice el encabezado del script.

---

## El tablero

### La partición

| Serie | Qué trae | Fila |
| --- | --- | --- |
| `A_COBRAR` | La cartera (`ESTADO = 'C'`) **cobrable**, en **bruto** y en la fecha del cheque | *Echeqs en cartera* |
| `A_COBRAR_EXCLUIDOS` | Lo excluido a mano, en bruto | — |
| `DEPOSITADOS` | Los depositados en Tango que se proyectan, en **neto** y en la fecha de **acreditación** | *Echeqs depositados* |
| `A_COBRAR_TODO` | Las tres juntas: lo que la cartera aporta al flujo | — |

```
A_COBRAR + A_COBRAR_EXCLUIDOS + DEPOSITADOS = A_COBRAR_TODO
universo en bruto = A_COBRAR_TODO + retención de los depositados
```

**Cada cheque está en una sola parte**: las dos primeras salen de la cartera (`C`) y la tercera de los depositados (`A`), y un cheque no puede estar en los dos estados. Las partes cierran contra el total **columna por columna**; lo fijan las pruebas, también contra los datos reales.

**La cartera sigue agregada** (`getEcheqsCarteraTotales()`, un `GROUP BY` por fecha y excluido). **Los depositados van fila por fila**, porque la acreditación y el neto se calculan en PHP con el calendario y las alícuotas; son pocos. `EcheqsProvider::itemsDeDepositados()` los convierte en ítems del reparto sin recalcular nada.

**La retención viaja como escalar de `DEPOSITADOS`** —`bruto` y `retencion`, como `fuera_horizonte`—, y no como series: una serie se puede elegir para una fila, y `DEPOSITADOS_BRUTO` al lado de `DEPOSITADOS` contaría dos veces el mismo cheque. Los escalares se auditan en `EcheqsProvider::repartir()`; el motor los descarta al normalizar la serie.

**El validador no cambió**: `componentes` declara `A_COBRAR_TODO => [A_COBRAR, A_COBRAR_EXCLUIDOS, DEPOSITADOS]`.

### Lo demás

- Una acreditación **fuera del eje** va a `fuera_horizonte`, en `DEPOSITADOS` y en el total, y el tablero avisa.
- Los avisos de los depositados —sin plazo, sin hábil, sin alícuotas, calendario faltante, boleta sin cuenta— salen en el tablero y en la pestaña con su nivel.
- Si hay depositados que se proyectan y **ninguna fila activa muestra `ECHEQS → DEPOSITADOS`**, la pestaña lo avisa como **crítico**: esa plata no está en ninguna fila. Se pregunta por la serie y no por el código de la fila (`Echeqs::filaDepositadosActiva()`).
- **Venta Cobrada Anticipada no cambia**: el neteo va por tilde, no por estado ni por depósito. Un cheque pre-chequeado que se deposita sigue neteando la venta en la fecha del cheque.

---

## La grilla de Cheques en Cartera

**Los depositados se ven en la misma grilla que la cartera**: el controller junta las dos lecturas (`getEcheqsCartera()` y `getDepositadosTango()`) y las ubica en el eje con `Echeqs::ubicarEnEje()`, la misma regla del proveedor.

| Qué | Cómo |
| --- | --- |
| **Dónde cae el importe en el eje** | Uno de cartera, en la fecha del cheque y por el bruto; un depositado que se proyecta, en su acreditación y por el neto; uno acreditado o sin datos, en ninguna columna. El eje se arma con `FECHA_EJE` / `IMPORTE_EJE` |
| **Columnas Acredita, Retención y Neto** | **Siempre visibles**, vacías —no en cero— en los de cartera, entre *Importe* y la de selección. Aparecer y desaparecer movería los índices de las columnas fijas, el orden guardado y lo que baja el export |
| **Marcas** | **depositado** (se proyecta), **acreditado (por plazo)** (acredita hoy), **acreditado** (marcado) y **depositado, sin proyectar** (le falta un dato) |
| **Tooltip de la marca** | La boleta de Tango —número y fecha—, la cuenta donde se depositó con su descripción, `TIPO_SAL` y `CTA_DESTIN`, el banco emisor, la fecha del cheque, la acreditación con el plazo, el bruto, la tasa con su desglose y el neto. Si está marcado: quién, cuándo, el origen y la observación, y las marcas deshechas |
| **Ocultar depositados** | Al lado de *Ver excluidos*. **Por defecto se ven**: siguen entrando al cashflow hasta que acreditan. Esconde también los acreditados y los sin proyectar; sus avisos salen siempre |
| **Tarjetas** | Las tres de siempre muestran **la cartera cobrable** —lo mismo que *Echeqs en cartera*—; la cuarta, **los depositados que se proyectan** con bruto, retención y neto, y cuántos acreditados o sin proyectar hay aparte. Las restas las hace PHP |
| **Pie** | La fila *TOTALES* suma lo que se ve, y debajo se abre en *En cartera*, *Depositados*, *Acreditados · no suman*, *Depositados sin proyectar · no suman* y *Excluidos · no suman* |
| **Selección** | La barra dice cuántos, cuánto y de qué banco. *Excluir* no se habilita con un depositado —ya salió de cartera—; *Ya acreditó* sólo si todos se proyectan; *Deshacer ya acreditó* sólo si todos están marcados |
| **Avisos** | Los de los depositados, el de la fila del tablero y el del script de la marca entran al panel plegable de la pestaña |
| **Exportar** | Sale del DOM vivo: baja las columnas nuevas con lo que se ve |

---

## Parámetros › Cheques

La sub-pestaña **Pre-chequeado pasó a llamarse Cheques**. Cambió sólo el nombre visible: **el código de módulo sigue siendo `PRECHEQUEADO`**, que es la clave de los permisos ya asignados en apps y la de la carga diferida. Aloja el maestro de **venta cobrada anticipada** y, arriba, la sección **Depósito de cheques** —va primero porque mueve plata del tablero—.

### El plazo de acreditación

`echeqs_dias_acreditacion_deposito` en `RO_T_CASHFLOW_PARAMETROS`, módulo `PRECHEQUEADO`, grupo `DEPOSITO`. **Días hábiles bancarios** después de la boleta de depósito: un entero de **1 a 30**.

- **Tiene su propio camino de guardado** (`Echeqs::guardarPlazo()`, acción `savePlazoDepositoEcheq`), que valida. `Parametros::saveParametro()` **rechaza esa clave**: guarda cualquier texto, y un plazo de cero o de "dos" dejaría a los depositados sin proyectar.
- **El tope de 30 no es del banco**: es el de un error de tipeo, y coincide con `DiasHabiles::MAX_CORRIMIENTO`.
- **Un plazo que falta o está roto es `null` con el motivo, nunca un 2 de respaldo** (`Echeqs::plazoDesdeValor()`): los depositados no se proyectan y avisan como crítico.
- **Cambiarlo mueve también los que todavía no acreditaron**: la fecha se calcula al leer, desde la boleta.

### Las alícuotas de retención de los cheques

Es **el mismo mecanismo que las alícuotas de una procesadora de Cob. Electrónicos**, con un juego propio: conceptos libres, **vigencias** —una alícuota no se edita, se inserta una vigencia nueva con su `VIGENCIA_DESDE`—, la suma de las vigentes **menor a 100%** validada sobre el estado resultante, y dos vigencias del mismo concepto y la misma fecha **no se bloquean**: gana la insertada después. Ver *Por qué las alícuotas son un histórico* en `README-cob-electronicos.md`.

**Es una tabla propia, `RO_T_CASHFLOW_ECHEQ_ALICUOTA`, y no filas en la de Cob. Electrónicos**: con dos tablas, un cambio en la tasa de Payway no puede mover el neto de un cheque ni al revés. Lo prueba `tests/test_retenciones.php`.

La pantalla muestra **la tasa vigente hoy con su desglose**. Sin alícuotas vigentes dice **`sin alícuotas`, no `0%`**. Cuál alícuota rige hoy lo decide el servidor con la misma regla que calcula la tasa.

**Se puede dar de baja la última alícuota.** Sin ninguna vigente a su acreditación, un cheque depositado **queda sin neto**: no se proyecta y la pestaña Echeqs lo avisa como crítico. Un neto igual al bruto informaría de más sin decirlo.

**Guardar o dar de baja una alícuota no recalcula nada**: no hay ningún neto guardado. Los depositados que todavía no acreditaron toman la vigencia nueva en la próxima lectura; el panel del resultado lo dice.

---

## La regla de la tasa vive una sola vez: `Class/Retenciones.php`

| Helper | Qué decide |
| --- | --- |
| `alicuotasVigentes()` / `tasaRetencion()` | De cada concepto, la última vigencia con `VIGENCIA_DESDE <=` la fecha, con desempate por `ID`, sumadas. Cero conceptos no es tasa cero |
| `importeNeto()` | `bruto × (1 − tasa)`, redondeado a 4 decimales. **La única implementación de la fórmula** |
| `resolverTasa()` | La tasa o un rechazo con el motivo. Los textos —de quién son las alícuotas y dónde se cargan— los pone quien llama |
| `validarAlicuota()` | Concepto, rango y suma resultante menor a 1 |
| `cortePendientes()` | Mañana: lo que acredita hoy ya está en el saldo bancario |
| `planRecalculo()` | Qué pendientes cambian. Lo usa sólo Cob. Electrónicos: los cheques no guardan neto |

---

## Lo que se fue

- **El botón *Depositar* y *Deshacer depósito***, la vista previa y el diálogo; las acciones `previsualizarDeposito`, `depositarCheques` y `deshacerDepositos` (controller y `AuthCashflow`); los métodos que sólo usaba ese circuito.
- **El estado "acreditado, falta registrar en Tango"** y su aviso: el registro en Tango es ahora lo primero que pasa.
- **`recalcularDepositosPendientes()`** y su panel: no hay neto guardado que recalcular.

### `RO_T_CASHFLOW_ECHEQ_DEPOSITO`, en desuso

Era la tabla de ese circuito: un depósito por cheque, con su fecha, su tasa y su neto guardados. Tesorería nunca lo usó —registra el depósito en Tango—, y **lo que tiene son pruebas**: 4 filas, todas dadas de baja, al 16/10/2026.

- **No se lee**: ningún dato suyo entra al tablero ni a la pestaña. Lo fija una prueba sobre el código de `Class/`, `Controller/`, `Js/` y `Tabs/`.
- **No se borra la tabla ni sus filas**: no hay bajas físicas.
- **`sql/cashflow_echeqs_deposito.sql` ya no la crea**: una instalación nueva no tiene por qué tener una tabla que nadie lee. Donde ya existe, no se toca.
- **Que el script de las alícuotas se corrió lo dice la tabla de alícuotas** (`Echeqs::alicuotasCreadas()`, antes `depositoCreado()`, que miraba las dos tablas).

---

## Pruebas

`tests/test_echeqs_deposito.php`, sin base salvo las lecturas:

- el script de las alícuotas y el plazo, y que ya no crea la tabla vieja;
- el plazo, las alícuotas y que cambiarlas no recalcula nada;
- **qué es un depósito**: BDE `A` a un banco sí; `O/P`, cartera, `R`, `X`, la centinela, fuera de [FL], la cuenta comitente y la caja no; `TIPO_SAL = 'O'` sí; sin la línea de la boleta, sí con aviso;
- **la fecha y el importe**: viernes → martes, el feriado en el medio, la fecha del cheque que no interviene, el neto con la alícuota vigente a la acreditación, un cambio de alícuota o de plazo que se ve sin recalcular, el calendario faltante, sin hábil, sin alícuotas y sin plazo;
- **cuánto se proyecta**: mañana sí, hoy y antes no, marcado no, `INTERBANKING` igual que `MANUAL`, deshecha la marca vuelve;
- la lectura, los avisos y el excluido que se deposita;
- las consultas, y que nada consulta `RO_T_CASHFLOW_ECHEQ_DEPOSITO`;
- el circuito eliminado y sus textos;
- la partición del proveedor columna por columna, `A_COBRAR_TODO` que cierra, y **la pestaña y el proveedor que suman lo mismo**;
- *Ya acredito*: todo o nada sobre los que se proyectan, un solo vigente, deshacer, lo que se graba, los permisos y el script.

`tests/test_echeqs.php` verifica la partición **contra los datos reales**: las tres partes suman el total y los depositados que se listan son BDE a un banco sin vencer. `tests/test_tablas_controles.php`, el cableado: no quedan botones ni acciones de *Depositar*, y están *Ya acreditó*, su acción y su permiso. `tests/test_dias_habiles.php` fija `habilesDespues()`, y `tests/test_retenciones.php` la clase compartida.

Las escrituras de la marca se verificaron contra la base con una `#temporal` con el cuerpo del script, sin tocar tablas `dbo`: marcar, rechazar la segunda marca, el índice de vigentes, deshacer con su historial, la referencia dada de baja que no vuelve a entrar y el `CHECK` del origen.

---

## Archivos

```
sql/cashflow_echeqs_deposito.sql                Alicuotas de cheques y plazo (la tabla de depositos, en desuso)
sql/cashflow_estructura_echeqs_depositados.sql  La fila Echeqs depositados
sql/cashflow_echeqs_acreditado.sql              La marca "ya acredito"
Class/Retenciones.php                           La tasa de retencion, compartida con Cob. Electronicos
Class/DiasHabiles.php                           habilesDespues(): N habiles despues de una fecha
Class/Echeqs.php                                Que es un deposito, donde cae y por cuanto, la lectura, la marca
Class/Providers/EcheqsProvider.php              La particion: A_COBRAR, EXCLUIDOS, DEPOSITADOS, TODO
Class/CashflowRegistry.php                      La serie DEPOSITADOS y su componente
Class/Parametros.php                            Sub-pestana Cheques: seccion deposito
Controller/EcheqsController.php                 getEcheqsCartera, marcarAcreditados, deshacerAcreditados
Controller/ParametrosController.php             savePlazoDepositoEcheq, addAlicuotaEcheq, bajaAlicuotaEcheq
Tabs/echeqs.php, Js/Ingresos-Echeqs.js          La grilla
Tabs/parametros_prechequeado.php                Parametros > Cheques
Js/Parametros-Prechequeado.js
tests/test_echeqs_deposito.php, tests/test_retenciones.php
```
