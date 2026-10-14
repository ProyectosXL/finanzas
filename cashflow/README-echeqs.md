# Echeqs — el depósito masivo de cheques de cartera

*Ingresos › Echeqs › Cheques en Cartera* y *Parámetros › Cheques*. Lo demás de Echeqs —las dos sub-pestañas, la exclusión de cartera y la venta cobrada anticipada— está en `README-cashflow.md` (*Excluir un cheque que no se va a poder cobrar*) y en `README-ventas.md` (el neteo de cheques adelantados).

## La idea en una línea

Se seleccionan cheques de cartera **de cualquier banco emisor** —se cobran por el **clearing bancario**, así que una misma tanda junta cheques de varios bancos— y se marcan como depositados. Desde ese día corren **48 hs hábiles** —el plazo de Parámetros—, y el cheque **sale** de la fila *Echeqs en cartera* y **entra** en *Echeqs depositados* el día que acredita, **por el neto**: el importe menos la retención del banco.

```
neto = bruto × (1 − tasa)
tasa = suma de las alícuotas de los cheques vigentes a la fecha de ACREDITACIÓN
```

Deja de proyectarse solo, por cualquiera de dos causas: **Tango registra el depósito** —el cheque sale de cartera— o **llega el día de acreditación**.

---

## Para pasar a producción: los scripts, en orden

Contra `central`, los dos reejecutables y sin borrar nada. **El día que se corren, el tablero no se mueve un peso**: no hay ningún cheque depositado, así que `A_COBRAR` vale lo mismo que antes y la fila nueva nace en cero.

| # | Script | Qué hace | Si no se corre |
| --- | --- | --- | --- |
| 1 | `sql/cashflow_echeqs_deposito.sql` | Crea `RO_T_CASHFLOW_ECHEQ_DEPOSITO` (los depósitos, con historial) y `RO_T_CASHFLOW_ECHEQ_ALICUOTA` (las alícuotas de los cheques, con vigencias), y siembra el plazo `echeqs_dias_acreditacion_deposito` en **2** sin pisarlo si ya existe. **No siembra alícuotas** | La pestaña y el tablero funcionan **como hoy**. *Depositar* queda apagado nombrando el script y *Parámetros › Cheques* avisa |
| 2 | `sql/cashflow_estructura_echeqs_depositados.sql` | Crea la fila **Echeqs depositados** (`ECHEQS_DEPOSITADOS`) en *Disponibilidades*, `ORDEN = 25`, `ECHEQS → DEPOSITADOS`, `INGRESO`, `COMPUTA = 1`. `MERGE` sobre `CODIGO`: no pisa nombre ni orden editados. Al pie lista las filas de `ECHEQS` y corta si alguna activa usa `A_COBRAR_TODO` | ***Depositar* queda apagado**, nombrando este script. Ver *El hueco entre los dos scripts* |

**El 1 va después de `sql/cashflow_auditoria_usuario.sql`** (las tablas nacen con su esquema de auditoría) **y de `sql/cashflow_echeqs_excluir.sql`** (un excluido no se puede depositar). **El 2 va después del 1** y de `sql/cashflow_estructura_disponibilidades.sql`, que crea la sección.

Después de correrlos, **antes de depositar nada**: cargar las alícuotas en *Parámetros › Cheques* (hoy IIBB y SICREB). Sin ninguna vigente, el depósito se rechaza diciendo que falta cargarla.

### Después de correrlos, verificar

- *Echeqs en cartera* (`A_COBRAR`) y *Echeqs depositados* (`DEPOSITADOS`) **activas las dos**, en los órdenes 20 y 25. Son dos partes distintas: activas juntas es lo correcto.
- **Ninguna fila activa con `ECHEQS → A_COBRAR_TODO`**: es el total, incluye a las dos, y el validador lo rechaza al lado de cualquiera. Verificado contra la base el 13/10/2026: la única fila de `ECHEQS` es *Echeqs en cartera*.
- El tablero, antes de depositar nada, **igual que antes**: verificado el 13/10/2026 sin los scripts, 370 cheques por $ 1.949.963.716,15 en `A_COBRAR` y en `A_COBRAR_TODO`, `DEPOSITADOS` en cero.

---

## Parámetros › Cheques

La sub-pestaña **Pre-chequeado pasó a llamarse Cheques**. Cambió sólo el nombre visible: **el código de módulo sigue siendo `PRECHEQUEADO`**, que es la clave de los permisos ya asignados en apps y la de la carga diferida. Aloja dos cosas: el maestro de **venta cobrada anticipada**, igual que antes, y la sección nueva **Depósito de cheques**, arriba —va primero porque mueve plata del tablero—.

### El plazo de acreditación

`echeqs_dias_acreditacion_deposito` en `RO_T_CASHFLOW_PARAMETROS`, módulo `PRECHEQUEADO`, grupo `DEPOSITO`. **Días hábiles bancarios** después del depósito: un entero de **1 a 30**. Lleva el prefijo del módulo, como `exportaciones_tasky_dias_cobro`: la tabla clave/valor es una sola para todo el cashflow.

- **Tiene su propio camino de guardado** (`Echeqs::guardarPlazo()`, acción `savePlazoDepositoEcheq`), que valida. `Parametros::saveParametro()` **rechaza esa clave**: guarda cualquier texto, y un plazo de cero o de "dos" dejaría sin poder depositar.
- **El tope de 30 no es del banco**: es el de un error de tipeo, y coincide con `DiasHabiles::MAX_CORRIMIENTO`.
- **Un plazo que falta o está roto es `null` con el motivo, nunca un 2 de respaldo** (`Echeqs::plazoDesdeValor()`). Un respaldo escrito en el código depositaría con un número que nadie eligió.
- **Cambiarlo no mueve los depósitos ya hechos.** La fecha de acreditación se calcula y **se guarda** al depositar, con `DIAS_PLAZO` al lado para poder explicarla.

### Las alícuotas de retención de los cheques

Es **el mismo mecanismo que las alícuotas de una procesadora de Cob. Electrónicos**, con un juego propio: conceptos libres (no es un enum), **vigencias** —una alícuota no se edita, se inserta una vigencia nueva con su `VIGENCIA_DESDE`—, la suma de las vigentes **menor a 100%** validada sobre el estado resultante, y dos vigencias del mismo concepto y la misma fecha **no se bloquean**: gana la insertada después (desempate por `ID`). Ver *Por qué las alícuotas son un histórico* en `README-cob-electronicos.md`.

**Es una tabla propia, `RO_T_CASHFLOW_ECHEQ_ALICUOTA`, y no filas en la de Cob. Electrónicos.** Aquella cuelga de una procesadora; compartirla exigiría una procesadora "Cheques" que aparecería en el alta de movimientos y en el ABM de procesadoras. Con dos tablas, **un cambio en la tasa de Payway no puede mover el neto de un cheque ni al revés**, y eso no depende de que nadie se acuerde de filtrar. Lo prueba `tests/test_retenciones.php`.

La pantalla muestra **la tasa vigente hoy con su desglose**. Sin alícuotas vigentes dice **`sin alícuotas`, no `0%`**: un cero se leería como "el banco no retiene nada". Cuál alícuota rige hoy —la marca `vigente`, `histórica` o `futura`— la decide el servidor con la misma regla que calcula la tasa.

**Se puede dar de baja la última alícuota**, a diferencia de Cob. Electrónicos —allá el invariante es "una procesadora *activa* tiene alícuotas", y acá no hay procesadora—. Sin alícuotas, los depósitos pendientes **conservan el neto que tienen guardado** —el recálculo no puede calcular uno nuevo y lo avisa— y **los depósitos nuevos se rechazan** hasta que se cargue una. Decisión de Tesorería.

### El recálculo al cambiar una alícuota

Igual que Cob. Electrónicos: al cargar o dar de baja una alícuota se recalculan los depósitos **vigentes con acreditación de mañana en adelante**, **en una transacción**, y la pantalla muestra en un panel cuántos cambiaron, por cuánta plata y el detalle de cada uno (tasa y neto antes → después). **Los que ya acreditaron, hoy incluido, no se tocan**: su plata entró con la tasa que entró. El corte es `Retenciones::cortePendientes()`, **el mismo de Cob. Electrónicos**.

---

## La regla de la tasa vive una sola vez: `Class/Retenciones.php`

Nació en `CobElectronicos`. Cuando los cheques pasaron a acreditar un neto con la misma regla, la alternativa era copiarla, y dos copias de la fórmula del neto son dos fórmulas. Se extrajo a **`Class/Retenciones.php`**, estática y pura:

| Helper | Qué decide |
| --- | --- |
| `alicuotasVigentes()` / `tasaRetencion()` | De cada concepto, la última vigencia con `VIGENCIA_DESDE <=` la fecha, con desempate por `ID`, sumadas. Cero conceptos no es tasa cero |
| `importeNeto()` | `bruto × (1 − tasa)`, redondeado a 4 decimales. **La única implementación de la fórmula** |
| `resolverTasa()` | La tasa o un rechazo con el motivo. Los textos —de quién son las alícuotas y dónde se cargan— los pone quien llama |
| `validarAlicuota()` | Concepto, rango y suma resultante menor a 1 |
| `cortePendientes()` | Mañana: lo que acredita hoy ya está en el saldo bancario |
| `planRecalculo()` | Qué pendientes cambian. Recalcula **por juego** de alícuotas: la procesadora en Cob. Electrónicos, uno solo en Echeqs |

**Los métodos de `CobElectronicos` no cambiaron de nombre ni de contrato**, con los mismos mensajes: delegan. Sus pruebas siguen pasando sin tocarse, que era la red del refactor. Es el mismo criterio con el que se extrajo `Class/Planilla.php`.

---

## El depósito

### Dónde

En *Cheques en Cartera*, la barra de selección suma **Depositar** y **Deshacer depósito**, al lado de *Excluir*. Se dibujan sólo con permiso de edición de Echeqs (`['echeqs', null]`, como `excluirCheques`).

### Qué cheques se pueden depositar juntos

**Todo o nada**: con un solo cheque que no cumple, no se deposita ninguno y la respuesta dice cuáles y por qué. El servidor vuelve a validar todo contra la lectura de la base (`Echeqs::validarSeleccionDeposito()`):

| Regla | Por qué |
| --- | --- |
| **En cartera hoy** en Tango (`ESTADO = 'C'`) | Un id del navegador no es una autorización. Mismo criterio que `excluirCheques()` |
| **No depositado** | Un depósito vigente no se vuelve a hacer: se deshace |
| **No excluido** | Un cheque que se decidió que no entra no se deposita: primero hay que devolverlo |
| **Con banco** | Es un control defensivo: el banco emisor es obligatorio al cargar el cheque en Tango, así que uno sin banco es un dato roto, y no se guarda un depósito sin banco. Un `ID_BANCO` que no está en `dbo.BANCO` cuenta como sin banco |
| **Con importe** | Un depósito de cero o negativo restaría del tablero |

**Los bancos se pueden mezclar.** Los cheques se cobran por el clearing bancario, así que una tanda lleva cheques de cualquier banco emisor, con **el plazo y la tasa únicos** de *Parámetros › Cheques*: ninguno de los dos depende del emisor. Cada cheque guarda **su propio banco** (ver *El modelo*).

**Por qué todo o nada y no "lo que se pueda":** el depósito es una tanda real que alguien llevó al banco. Si una parte se guarda y otra no, la pantalla dice algo distinto de lo que pasó en el banco.

### El diálogo

Antes de confirmar se pide una **vista previa** (`previsualizarDeposito`, que no escribe y va con el permiso de edición, como las de las importaciones). El diálogo muestra lo que calculó el servidor: cuántos cheques y de qué banco —*N cheque(s) de GALICIA*, o *N cheques de K bancos*—, bruto, **tasa vigente a la fecha de acreditación con su desglose**, retención, neto, fecha de depósito (hoy) y de acreditación. Si la acreditación se calculó con el respaldo de lunes a viernes porque falta el calendario, **lo dice**. No tiene campos editables salvo una **observación opcional**.

**Con varios bancos**, debajo de *N cheques de K bancos* va **el desglose por banco**: nombre, cantidad, bruto y neto de cada uno, de mayor a menor bruto, y abajo los totales de siempre. Lo arma y lo ordena el servidor (`Echeqs::desgloseBancos()`), agrupado por `ID_BANCO`; el neto de cada banco es la suma de los netos ya redondeados de sus cheques, así que los bancos suman exactamente el total. Va en una caja de altura fija con scroll propio: con muchos bancos el diálogo no pasa del alto de la pantalla.

**El texto de la tanda sale de un solo lugar**, `Echeqs::textoTanda()`, y viaja armado en el cálculo: lo usan el diálogo, el mensaje del controller y el aviso de éxito (*3 cheques de 2 bancos depositados…*). El título del botón *Depositar* se arma en la pantalla —se pinta al seleccionar, sin preguntarle al servidor— con el mismo criterio.

Si no se puede depositar, lo dice con el motivo; si se arregla en *Parámetros › Cheques* —faltan alícuotas o el plazo está roto— ofrece **ir ahí**. La navegación usa `window.cfSubTabDestino`, el mismo mecanismo con el que el tablero abre una sub-pestaña de Saldos, que ahora también lee `Js/parametros-carga.js`.

### Qué se calcula, en el servidor

**Del navegador viajan sólo los ids de los cheques.** Banco, importe, fecha de depósito, acreditación, tasa y neto los resuelve el servidor (`Echeqs::calcularDeposito()`), y **los vuelve a calcular al guardar**: lo que se guarda es lo del momento de confirmar, no lo que mostró la vista previa.

| Dato | Regla |
| --- | --- |
| Banco | **El de cada cheque**, de la lectura de la cartera. No hay un banco de la tanda |
| Fecha de depósito | **Hoy.** No se elige |
| Acreditación | El **N-ésimo día hábil bancario posterior** al del depósito (`Echeqs::fechaAcreditacion()` → `DiasHabiles::habilesDespues()`). Con 2: lunes → miércoles; viernes, sábado o domingo → martes; un depósito hecho un feriado cuenta desde el hábil siguiente |
| Tasa | La suma de las alícuotas **vigentes a la fecha de acreditación**, como Cob. Electrónicos: es la tasa del día en que entra la plata |
| Neto | `Retenciones::importeNeto()`, **cheque por cheque**; el total es la suma. Es lo que se guarda y lo que suma el tablero |

**La fecha del cheque no interviene, y es una decisión de negocio**: el banco acredita a las 48 hs hábiles de recibir el depósito. Un cheque fechado dentro de veinte días depositado hoy acredita pasado mañana.

**Un depósito no puede quedar sin neto**: sin alícuotas vigentes a la fecha de acreditación se rechaza con el motivo, como el alta de un movimiento de Cob. Electrónicos. Sin ningún día hábil en el tope del calendario, también: no se inventa una fecha.

El calendario es `RO_T_CALENDARIO`, por la única lectura del módulo (`Ventas::getDiasHabiles()`), con el respaldo de lunes a viernes y el aviso de calendario faltante de siempre.

### El modelo

`RO_T_CASHFLOW_ECHEQ_DEPOSITO`, una fila por cheque depositado:

| Columna | Qué guarda |
| --- | --- |
| `ID` | `IDENTITY`, PK. **No es `ID_SBA14`**: un cheque puede tener varias filas en el tiempo |
| `ID_SBA14`, `N_CHEQUE` | El cheque. El número va como copia, para leer el historial sin depender de Tango |
| `ID_BANCO`, `DESC_BANCO` | El banco **de ese cheque al momento del depósito**, no el de la tanda: una tanda junta varios bancos. Lo arma `Echeqs::paramsInsertDeposito()`, cheque por cheque, y es el que muestra el tooltip de la marca |
| `FECHA_DEPOSITO`, `FECHA_ACREDITACION`, `DIAS_PLAZO` | Calculados y persistidos. `CHECK` de plazo ≥ 1 y acreditación posterior al depósito |
| `IMPORTE_BRUTO`, `TASA_APLICADA`, `DETALLE_TASA`, `IMPORTE_NETO` | Persistidos, `NOT NULL` salvo el desglose. El recálculo reescribe tasa, desglose y neto de los pendientes |
| `OBSERVACION` | Opcional |
| `VIGENTE` y las seis de auditoría | Alta, modificación y baja |

**Índice único filtrado `WHERE VIGENTE = 1` sobre `ID_SBA14`**: un solo depósito vigente por cheque. PHP ya rechaza depositar un cheque depositado; el índice es lo que frena a dos pestañas que mandan el mismo cheque a la vez. **Sin FK contra `dbo.SBA14`**, por el mismo motivo que la exclusión: es una tabla de Tango.

### Deshacer

**Baja lógica**, uno o varios a la vez, con confirmación, todo o nada y en una transacción: `VIGENTE = 0` con quién y cuándo. El cheque vuelve a *Echeqs en cartera* **en la fecha del cheque, si es de hoy en adelante**; si ya pasó, no reaparece. *Deshacer depósito* se habilita sólo si **todos** los seleccionados están depositados.

El historial —quién depositó, cuándo, quién lo deshizo— se ve en el **tooltip de la marca**, y un cheque cuyo depósito se deshizo lleva un ícono con su historia.

**Un cheque depositado no se excluye**, y un excluido no se deposita. Las dos marcas sobre el mismo cheque lo pondrían en dos series a la vez; con las dos reglas, cada cheque está en una sola parte.

---

## Qué se lee y qué se proyecta

`Echeqs::estadoDeposito()` decide, y es **la misma función** para la pestaña y para el proveedor. Un depósito vigente **se proyecta** mientras:

1. el cheque **siga en cartera** en Tango (`ESTADO = 'C'`), y
2. acredite **de mañana en adelante**.

| Caso | Qué pasa |
| --- | --- |
| Depositado, acredita de mañana en adelante | Entra en *Echeqs depositados* el día que acredita, por el neto |
| **Acredita hoy** | **No se proyecta.** Mismo criterio que Cob. Electrónicos: lo que acredita hoy ya está —o va a estar al cierre— en el saldo bancario de Saldos, y se contaría dos veces |
| Acreditó (hoy o antes) y **sigue en cartera** | No suma a ninguna fila. Marca **"acreditado, falta registrar en Tango"** y un aviso de **atención**: *"N cheque(s) depositados por $ X netos ya acreditaron y siguen en cartera en Tango: registrá el depósito"* |
| **Tango registró el depósito** (salió de cartera, en cualquier estado) | Sale solo, aunque no hayan pasado las 48 hs. **Sin aviso**: es lo esperado |
| Rechazado (`R`, `X`) | Sale solo, como siempre |

> **Esto corrige la especificación original**, que proyectaba lo que acredita hoy. Se cambió para que los dos módulos con acreditaciones corten igual: si uno contara la acreditación de hoy y el otro no, la misma plata se contaría en una fila y no en la otra, y el saldo bancario la contaría por tercera vez o por ninguna.

### La lectura: los depositados se leen aunque su fecha haya pasado

`getEcheqsCartera()` y `getEcheqsCarteraTotales()` leían sólo `FECHA_CHEQ >= hoy`. Un cheque con fecha de hoy depositado hoy saldría mañana de la lectura y desaparecería del flujo antes de acreditar. Ahora:

```sql
WHERE (s.FECHA_CHEQ >= CAST(GETDATE() AS DATE) OR d.ID IS NOT NULL)
  AND s.ESTADO = 'C' AND s.CLIENTE LIKE '[FL]%'
```

**El depósito se une con `VIGENTE = 1` en la condición de la unión, no en el `WHERE`.** Con un depósito deshecho, `d.ID` queda en `NULL` y el cheque vuelve a la regla de los no depositados: depositado el día de su fecha y deshecho al día siguiente, **no reaparece**. En el `WHERE`, la fila histórica seguiría trayendo el cheque. La regla que decide es `Echeqs::visibleEnCartera()`, pura y probada; la consulta aplica lo mismo como optimización. Las dos consultas comparten el `WHERE` (`sqlUniversoCartera()`), así que la pestaña y el tablero miran la misma cartera.

**Es la misma excepción a "los cruces se hacen en PHP" que la exclusión**, y por un motivo más fuerte: el depósito cambia **qué filas se leen**. Cruzarlo en PHP obligaría a traer toda la cartera histórica para quedarse con los depositados.

---

## El tablero

### La partición

| Serie | Qué trae | Fila |
| --- | --- | --- |
| `A_COBRAR` | La cartera **cobrable y no depositada**, en **bruto** y en la fecha del cheque | *Echeqs en cartera* |
| `A_COBRAR_EXCLUIDOS` | Lo excluido a mano, en bruto | — |
| `DEPOSITADOS` | Los depositados que se proyectan, en **neto** y en la fecha de **acreditación** | *Echeqs depositados* |
| `A_COBRAR_TODO` | Las tres juntas | — |

```
A_COBRAR + A_COBRAR_EXCLUIDOS + DEPOSITADOS = A_COBRAR_TODO
universo en bruto = A_COBRAR_TODO + retención de los depositados
```

**Cada cheque está en una sola parte**, y las partes cierran contra el total **columna por columna**. Lo fijan las pruebas.

**`A_COBRAR_TODO` cambió de significado**: era *cobrable + excluido*, todo en bruto; ahora suma también los depositados, **en neto**. Ya no es el universo en bruto: es lo que la cartera aporta al flujo. Antes de cambiarlo se verificó que ninguna fila, script de estructura ni consumidor lo usara esperando el bruto: ninguna fila de la base lo usa, y en el código sólo lo leen el registro, el proveedor y sus pruebas.

**La retención viaja como escalar de `DEPOSITADOS`** —`bruto` y `retencion`, como `fuera_horizonte`—, y no como series `DEPOSITADOS_BRUTO` / `DEPOSITADOS_RETENCION`. Una serie se puede elegir para una fila, y `DEPOSITADOS_BRUTO` al lado de `DEPOSITADOS` contaría dos veces el mismo cheque; el validador no puede expresar "ésta va con `A_COBRAR` pero no con aquélla" sin cambiar cómo entiende los cortes. Los escalares se auditan en `EcheqsProvider::repartir()`; el motor los descarta al normalizar la serie.

**El validador no cambió**: `componentes` declara `A_COBRAR_TODO => [A_COBRAR, A_COBRAR_EXCLUIDOS, DEPOSITADOS]`, un solo corte. Las tres partes activas juntas son correctas; el total al lado de cualquiera, se rechaza.

**`A_COBRAR` cambió de significado otra vez y no de código, a propósito**: es el que la fila ya tiene configurado, así que el depósito entró sin repuntar ninguna fila.

### El hueco entre los dos scripts

Con el script del depósito y **sin** la fila *Echeqs depositados*, un cheque depositado saldría de `A_COBRAR` y no entraría en ninguna fila: el tablero informaría de menos **sin avisar**, porque cada serie es correcta por separado. Por eso:

- **No se puede depositar** sin una fila activa con `ECHEQS → DEPOSITADOS` (`Echeqs::filaDepositadosActiva()`, que pregunta por la serie y no por el código de la fila). El botón queda apagado nombrando el script, y el servidor lo rechaza igual.
- Si **hay depósitos vigentes y la fila no está activa** —alguien la desactivó después—, la pestaña lo avisa como **crítico**.

### Lo demás

- Una acreditación **fuera del eje** va a `fuera_horizonte`, en `DEPOSITADOS` y en el total, y el tablero avisa: es la serie de la fila la que se lee para avisar.
- **Venta Cobrada Anticipada no cambia**: el neteo va por tilde, no por estado ni por depósito. Un cheque pre-chequeado que se deposita sigue neteando la venta en la fecha del cheque.

---

## La grilla de Cheques en Cartera

| Qué | Cómo |
| --- | --- |
| **Dónde cae el importe en el eje** | Un depositado, en su **acreditación** y por el **neto**; uno acreditado sin registrar, en ninguna columna; los demás como siempre. Lo resuelve PHP (`Echeqs::ubicarEnEje()`, la misma regla del proveedor) y el eje se arma con `FECHA_EJE` / `IMPORTE_EJE` |
| **Columnas Acredita, Retención y Neto** | **Siempre visibles**, vacías —no en cero— en los no depositados, entre *Importe* y la de selección. Aparecer y desaparecer movería los índices de las columnas fijas, el orden guardado y lo que baja el export; ubicadas ahí, *N° Cheque* y *Cliente* siguen siendo la 1 y la 3 |
| **Marcas** | *depositado* y *acreditado, falta registrar en Tango*, con el detalle en el tooltip: quién y cuándo, acreditación, bruto, tasa con desglose, neto, observación y el historial |
| **Ocultar depositados** | Al lado de *Ver excluidos*. **Por defecto se ven**: siguen entrando al cashflow, y son los que hay que seguir hasta que Tango los registre |
| **Tarjetas** | Las tres de siempre muestran **la cartera cobrable sin depositados** —lo mismo que *Echeqs en cartera*—; una cuarta, **los depositados** con bruto, retención y neto. Las restas las hace PHP |
| **Pie** | La fila *TOTALES* suma lo que se ve, y debajo se abre en las partes que tienen fila en el tablero —*En cartera*, *Depositados*— y las que no suman, para que cada renglón se compare con la suya |
| **Selección** | La barra dice cuántos, cuánto y de qué banco —o de cuántos, si son varios—. *Excluir* no se habilita con un depositado; *Depositar* se apaga explicando por qué, y no por mezclar bancos |
| **Avisos** | Los acreditados sin registrar y el hueco entre scripts entran al panel plegable de la pestaña |
| **Exportar** | Sale del DOM vivo: baja las columnas nuevas con lo que se ve |

---

## Pruebas

`tests/test_echeqs_deposito.php`, sin base salvo las lecturas: el script; el plazo y su validación; las alícuotas, la tasa de hoy y "sin alícuotas"; el recálculo de los pendientes; la selección todo o nada (cheques de varios bancos que se depositan juntos, sin banco —también en una tanda mixta—, excluido, ya depositado, fuera de cartera); el cálculo (acreditación, tasa a la acreditación, neto, calendario faltante, sin hábil, la fecha del cheque que no interviene); el banco de cada cheque, el desglose por banco que suma la tanda y `textoTanda()`; los parámetros del INSERT, con el banco de cada cheque y no el del primero; que del navegador lleguen sólo los ids; deshacer como baja lógica; qué se proyecta —acreditación futura, de hoy, pasada, fuera de cartera, y el cheque deshecho que no reaparece—; la partición del proveedor columna por columna; el validador con la combinación nueva; el script de la fila; y las tarjetas.

`tests/test_dias_habiles.php` fija `DiasHabiles::habilesDespues()` con plazo 1, 2 y 3, fin de semana, feriado y calendario faltante. `tests/test_retenciones.php`, la clase compartida y que los dos juegos de alícuotas no se mezclen. `tests/test_tablas_controles.php`, el cableado de la grilla —el botón que no se apaga por mezclar bancos, el desglose del diálogo y su scroll— y de *Parámetros › Cheques*, que ningún archivo del circuito diga ya que un depósito es de un solo banco, y que las columnas nuevas no corran las fijas, el orden, el export ni los totales del eje.

---

## Archivos

```
sql/cashflow_echeqs_deposito.sql                Depositos, alicuotas de cheques y plazo
sql/cashflow_estructura_echeqs_depositados.sql  La fila Echeqs depositados
Class/Retenciones.php                           La tasa de retencion, compartida con Cob. Electronicos
Class/DiasHabiles.php                           habilesDespues(): N habiles despues de una fecha
Class/Echeqs.php                                Reglas del deposito, lecturas, depositar, deshacer, recalculo
Class/Providers/EcheqsProvider.php              La particion: A_COBRAR, EXCLUIDOS, DEPOSITADOS, TODO
Class/CashflowRegistry.php                      La serie DEPOSITADOS y su componente
Class/Parametros.php                            Sub-pestana Cheques: seccion deposito
Controller/EcheqsController.php                 previsualizarDeposito, depositarCheques, deshacerDepositos
Controller/ParametrosController.php             savePlazoDepositoEcheq, addAlicuotaEcheq, bajaAlicuotaEcheq
Tabs/echeqs.php, Js/Ingresos-Echeqs.js          La grilla
Tabs/parametros_prechequeado.php                Parametros > Cheques
Js/Parametros-Prechequeado.js
Js/parametros-carga.js                          Abre la sub-pestana de destino
tests/test_echeqs_deposito.php, tests/test_retenciones.php
```
