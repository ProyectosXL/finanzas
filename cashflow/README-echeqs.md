# Echeqs — la cartera, los vencidos y los depositados

*Ingresos › Echeqs › Cheques en Cartera* y *Parámetros › Cheques*: dónde cae un cheque de cartera —su **día hábil**, ver *El corrimiento al día hábil*—, los **cheques vencidos en cartera** y su fecha de acreditación interna, y los **depositados**, leídos de Tango. Lo demás de Echeqs —la exclusión de cartera y la venta cobrada anticipada— está en `README-cashflow.md` (*Excluir un cheque que no se va a poder cobrar*) y en `README-ventas.md` (el neteo de cheques adelantados).

## La idea en una línea

Tesorería deposita los echeqs en el banco y **registra el depósito en Tango en el mismo momento**, con una boleta de depósito (**BDE**): lo hace así a propósito, para que nadie le asigne ese cheque a un pago. El cheque pasa a `ESTADO = 'A'` y sale de cartera. El cashflow **lee ese depósito de Tango** y lo proyecta en *Echeqs depositados* **por el neto**, el día que acredita: **la fecha de la boleta más el plazo hábil de Parámetros** (hoy 2).

```
acreditación = N-ésimo día hábil después de FECHA_SAL      (N = el plazo de Parámetros)
neto         = bruto × (1 − tasa)
tasa         = suma de las alícuotas de los cheques vigentes a la fecha de ACREDITACIÓN
```

Deja de proyectarse solo, **sin aviso**: cuando **el extracto de Interbanking trae su crédito**, el día que acredita por plazo —la plata ya está en el saldo de Interbanking— o cuando alguien lo marca **ya acreditó**.

**Por qué cambió.** Antes el depósito se marcaba en el cashflow (botón *Depositar*) mientras el cheque seguía en cartera en Tango. Como Tesorería lo registra en Tango primero, el cheque salía de cartera antes de que nadie lo marcara, y **durante las 48 hs hábiles hasta que acreditaba esa plata no estaba en ninguna fila del tablero**: ni en cartera, ni en depositados, ni todavía en el saldo bancario. El botón *Depositar* se eliminó; ver *Lo que se fue*.

---

## Para pasar a producción: los scripts, en orden

Contra `central`, reejecutables y sin borrar nada.

| # | Script | Qué hace | Si no se corre |
| --- | --- | --- | --- |
| 1 | `sql/cashflow_echeqs_deposito.sql` | Crea `RO_T_CASHFLOW_ECHEQ_ALICUOTA` (las alícuotas de los cheques, con vigencias) y siembra el plazo `echeqs_dias_acreditacion_deposito` en **2** sin pisarlo si ya existe. **No siembra alícuotas.** Ya no crea `RO_T_CASHFLOW_ECHEQ_DEPOSITO` | Los depositados se listan **sin neto y sin proyectarse**, con un aviso crítico que nombra el script. *Parámetros › Cheques* avisa |
| 2 | `sql/cashflow_estructura_echeqs_depositados.sql` | Crea la fila **Echeqs depositados** (`ECHEQS_DEPOSITADOS`) en *Disponibilidades*, `ORDEN = 25`, `ECHEQS → DEPOSITADOS`, `INGRESO`, `COMPUTA = 1`. `MERGE` sobre `CODIGO`: no pisa nombre ni orden editados | Los depositados que se proyectan no entran en ninguna fila: la pestaña lo avisa como **crítico** |
| 3 | `sql/cashflow_echeqs_acreditado.sql` | Crea `RO_T_CASHFLOW_ECHEQ_ACREDITADO`: las marcas **ya acreditó**, con su origen e historial | El botón *Ya acreditó* no se dibuja, la pestaña avisa qué script falta y los depositados **se proyectan hasta su fecha de acreditación** |
| 4 | `sql/cashflow_echeqs_vencidos.sql` | Crea `RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION` —la fecha de acreditación interna de los vencidos, con historial— y siembra `echeqs_dias_vencidos_cartera` en **30** sin pisarlo | Los vencidos **no se leen**, la pestaña avisa qué script falta (atención; el tablero no dice nada) y todo lo demás —el corrimiento incluido— anda igual |

**El 1 y el 2 ya están corridos en producción** —verificado el 16/10/2026: las alícuotas (IIBB 2,5% y SICREB 0,6%), el plazo en 2 y las dos filas de `ECHEQS` activas—; volver a correr el 1 no cambia nada. **El 3 y el 4 son los nuevos**: van después de `sql/cashflow_auditoria_usuario.sql`, en cualquier orden entre ellos, y no dependen de los otros.

**El código se puede publicar antes que el 3 y el 4**: sin la marca, la pestaña y el tablero ya leen los depósitos de Tango; sin el 4, el corrimiento al día hábil ya rige y los vencidos esperan al script.

### Después de correrlos, verificar

- *Echeqs en cartera* (`A_COBRAR`) y *Echeqs depositados* (`DEPOSITADOS`) **activas las dos**, en los órdenes 20 y 25. Son dos partes distintas: activas juntas es lo correcto.
- **Ninguna fila activa con `ECHEQS → A_COBRAR_TODO`**: es el total, incluye a las dos, y el validador lo rechaza al lado de cualquiera.
- En la pestaña Echeqs aparecen los botones *Ya acreditó* y *Deshacer ya acreditó* al seleccionar un depositado, y el aviso del script de la marca ya no está.
- Después del 4: en *Parámetros › Cheques* aparece *Días hacia atrás para cheques vencidos* en 30, y en la pestaña Echeqs ya no está el aviso del script de los vencidos. Si hay vencidos, aparecen el aviso de *vencidos sin fecha* y la tarjeta *Vencidos en cartera*.

### Lo que mueve el tablero

Medido contra la base el **16/10/2026** con las clases reales, el criterio viejo y el nuevo uno detrás del otro sobre los mismos datos:

| Serie | Antes | Ahora |
| --- | --- | --- |
| `A_COBRAR` (*Echeqs en cartera*) | $ 1.940.142.016,30 | $ 1.940.142.016,30 |
| `DEPOSITADOS` (*Echeqs depositados*) | $ 0 | **$ 37.826.439,63**, todo el lunes 19/10 |
| `A_COBRAR_TODO` | $ 1.940.142.016,30 | $ 1.977.968.455,93 |

Los $ 37,8 M son los 5 cheques de franquicias de la boleta `02005000040735` del jueves 15/10 —$ 39.036.573,41 brutos a 3,1%—, que hasta hoy no estaban en ninguna fila. Los 3 de la boleta del miércoles 14/10 ($ 24.970.612,47) acreditan hoy: se listan *acreditado (por plazo)* y no suman. *Echeqs en cartera* no cambia: esos cheques ya habían salido de la lectura de cartera el día del depósito.

### Todos los cheques, sin filtro de cliente

**Cheques en Cartera lee todos los cheques de terceros de `SBA14`**, sin mirar el cliente: la cartera, los depositados y el resumen de excluidos. Hasta el 16/10/2026 filtraba por el prefijo del código, y cada cheque que quedaba afuera era plata que no estaba en ninguna fila del tablero. Primero se sumaron los mayoristas (`M`) y después se sacó el filtro entero, para que entren también los cheques sin cliente.

**No se cuentan dos veces:**

- **Los de un cliente** —franquicias, locales, mayoristas— entran a cartera con un recibo de cobro en cuenta corriente (`T_COMP_REC = 'REC'`) que ya canceló la factura en `GVA12`. Cobranzas FR y Cobranzas May proyectan sólo lo pendiente. Verificado el 16/10/2026: los 586 cheques `M` en cartera entraron por `REC`.
- **Los sin cliente** entran por CAJA INGRESO (`CAI`). Hoy son 6 de GO SERVICIOS DIGITALES (*"GO CUOTAS"*), diferidos a unos 60 días. El medio de pago **"Go Cuotas" del mix de cobro de Ventas** (LOCALES y ECOMMERCE) está **inactivo y en 0%**: **si alguien lo activa, esa plata se contaría en la cobranza proyectada de Ventas y acá**.

Medido el 16/10/2026 con las clases reales, cada paso uno detrás del otro sobre los mismos datos:

| Paso | `A_COBRAR` (*Echeqs en cartera*) | `DEPOSITADOS` (*Echeqs depositados*) |
| --- | --- | --- |
| Sólo franquicias y locales (`[FL]%`) | $ 1.973.944.870,87 | $ 37.826.439,63 |
| Con mayoristas (`[FLM]%`) | $ 2.455.077.645,33 (**+ $ 481.132.774,46**, 586 cheques) | $ 41.130.143,07 (**+ $ 3.303.703,44**, 6 cheques) |
| Sin filtro de cliente | $ 2.559.220.385,97 (**+ $ 104.142.740,64**, los 6 de GO) | $ 41.130.143,07 |

**Venta Cobrada Anticipada sigue en `[FL]%`**: es otro circuito, que netea la cobranza proyectada de Ventas por canal.

---

## Dónde cae un cheque de cartera: una sola regla

`Echeqs::resolverFechaCartera()`, pura, decide dónde cae cada cheque en cartera (`ESTADO = 'C'`) y en qué estado está. La usan la grilla (`ubicarEnEje()`), el proveedor del tablero y el resumen de excluidos: **la pestaña y el tablero ponen cada peso en la misma columna**, y lo fijan las pruebas, también contra los datos reales.

| Estado | Cuándo | Dónde cae |
| --- | --- | --- |
| `VIGENTE` | Su **día hábil** es de hoy en adelante | En su día hábil, por el bruto |
| `VENCIDO_CON_FECHA` | Su día hábil ya pasó, está dentro de la ventana, y tiene una fecha asignada de hoy en adelante | En la fecha asignada, por el bruto |
| `VENCIDO_SIN_FECHA` | Su día hábil ya pasó, está dentro de la ventana, y no tiene fecha | **En ninguna columna** |
| `FECHA_PASADA` | Vencido con una fecha asignada que ya pasó | **En ninguna columna** |
| `FUERA_VENTANA` | Vencido más allá de la ventana, sin ventana, o con la centinela `1800-01-01` | No se lista ni avisa |

**El día hábil** es la fecha del cheque corrida al hábil siguiente si cae un fin de semana o un feriado: ver *El corrimiento al día hábil*. **La ventana** se cuenta desde la fecha escrita: ver *Los cheques vencidos en cartera*.

### El corrimiento al día hábil

Un cheque en cartera cuya fecha cae un **sábado, un domingo o un feriado** de `RO_T_CALENDARIO` se ubica en el **primer día hábil siguiente** (`Echeqs::correrAHabil()` → `DiasHabiles::siguiente()`): el banco no acredita ese día. Es la misma regla para la pestaña y el tablero, en *Echeqs en cartera* y en *Echeqs excluidos*, y para **Venta Cobrada Anticipada y el neteo de Ventas**, con el mismo helper: el cheque y su neteo caen en la misma columna. Ver `README-ventas.md`.

| Caso | Ejemplo |
| --- | --- |
| Sábado o domingo | sáb 24/10 → lun 26/10 · *fin de semana* |
| Feriado | mar 08/12 → mié 09/12 · *feriado* |
| Viernes feriado | vie 20/11 → lun 23/11 · *feriado* |
| Feriado que cae en fin de semana | se informa como *fin de semana*: es lo que explica el corrimiento |
| Día hábil | no se corre |

- **El motivo sale de la fecha original**: sábado o domingo es fin de semana; cualquier otro día no hábil, feriado.
- **No se aplica** a la fecha asignada a un vencido —ya se exige hábil— ni a los depositados, que cuentan hábiles desde la boleta (`habilesDespues()`).
- **Después del fin del eje no se corre** (`Horizonte::fin()`, hoy 30/09/2027): ahí la fecha no cambia nada —el importe va a `fuera_horizonte` igual— y correrla pediría calendario de meses que `RO_T_CALENDARIO` no tiene (llega al 31/12/2027), con un aviso de calendario faltante por nada.
- **El calendario** se lee una vez por la única lectura del módulo (`Ventas::getDiasHabiles()`). Un mes que no está usa el respaldo de lunes a viernes, con el aviso de siempre (atención). **Sin hábil en el tope**, el cheque queda en su fecha con el aviso crítico de `avisoSinHabil()`: nunca se inventa una fecha.
- **El agregado del tablero se corre después del `GROUP BY`**, fecha por fecha: el corrimiento depende sólo de la fecha, así que todos los cheques de un grupo caen en el mismo día.
- **Un cheque del sábado leído el lunes cae hoy**, como uno fechado hoy: su día hábil es hoy. Por eso la lectura de cartera arranca en `primerDiaCartera()` —el día siguiente al último hábil— y no en `GETDATE()`. Leído el martes, ya es vencido.

En la grilla, *Fecha de pago* sigue siendo la del cheque; *Acredita* dice dónde cae el importe, y la marca **corrida** lo explica con un tooltip: *"sáb 24/10 → lun 26/10 · fin de semana"*. La marca va sólo en los vigentes: a un vencido no le dice dónde cae.

#### Lo que mueve el tablero

Medido contra la base el **21/10/2026** con las clases reales, `develop` y esta rama uno detrás del otro sobre los mismos datos:

| Serie | Antes | Ahora |
| --- | --- | --- |
| `A_COBRAR` (*Echeqs en cartera*) | $ 2.652.842.059,56 | $ 2.652.842.059,56 |
| `A_COBRAR_EXCLUIDOS` | $ 0,00 | $ 0,00 |
| `DEPOSITADOS` (*Echeqs depositados*) | $ 32.509.122,08 | $ 32.509.122,08 |
| `A_COBRAR_TODO` | $ 2.685.351.181,64 | $ 2.685.351.181,64 |
| *Echeqs en cartera* en sábados y domingos del tramo diario | $ 189.286.088,72 | **$ 0,00** |
| Neteo de Ventas (`NETEO_PRECHEQUEADO`) | $ 1.154.425.881,01 | $ 1.154.425.881,01 |

**El total no cambia: cambia la columna.** De los 1.013 cheques en cartera, 224 por $ 396.572.917,00 caen en fin de semana y 29 por $ 32.505.933,27 en feriado (20/11, 08/12, 25/12 y 01/01). En Venta Cobrada Anticipada, 28 cheques marcados por $ 168.434.285,84.

**Cambiar de día puede cambiar de mes.** Hoy son **25 cheques por $ 45.922.967,96**, todos del **sábado 31/10**, que caen el **lunes 02/11**. Por ejemplo, el cheque N° 9 de MARIA FLORENCIA VALLONE (CONCORDIA) por $ 6.775.631,04, fechado el sábado 31/10, acredita el lunes 02/11: el total de **octubre** de la vista *Meses* y de cualquier corte por mes baja $ 45,9 M y el de **noviembre** sube lo mismo. Ninguno sale del eje.

---

## Los cheques vencidos en cartera

Un cheque que sigue en cartera **después de su día hábil** es un **vencido**: Tesorería todavía no lo depositó. Hasta esta rama desaparecía del tablero sin aviso —la cartera se leía desde hoy—, aunque se fuera a depositar en unos días. Medido el 21/10/2026 sobre 180 días: **163 cheques salieron por boleta de depósito después de su fecha**, 15 de ellos con 4 a 26 días de atraso, y 86 por orden de pago; 108 eran de fin de semana y salieron a 1 o 2 días, que con el corrimiento ya no son vencidos.

**Ahora se leen dentro de una ventana, y sólo suman con una fecha.** Tesorería les asigna una **fecha de acreditación interna**, que sólo vive en el cashflow —no se escribe nada en Tango—. Sin fecha no suman en ningún lado: nadie dijo cuándo entra esa plata, y ponerla en una fecha inventada sería informar de más.

**El 21/10/2026 no hay ningún cheque en cartera con su día hábil antes de hoy**: el día que se corra el script el tablero no se mueve ni un peso.

### La ventana

`echeqs_dias_vencidos_cartera`, en *Parámetros › Cheques › Depósito de cheques*, debajo del plazo de acreditación: **días corridos hacia atrás desde la fecha del cheque** en que un vencido se sigue mostrando. Un entero de **1 a 30**; el script lo siembra en **30**, el plazo legal para presentar un cheque al cobro.

- Entra un cheque con fecha de **hoy − N en adelante**; el de hoy − N − 1, no. Se cuenta **desde la fecha escrita**, no desde la corrida: es el plazo para presentarlo.
- **Tiene su propio camino de guardado**, como el plazo: `Echeqs::guardarVentana()`, acción `saveDiasVencidosEcheq`, permiso `['parametros', 'PRECHEQUEADO']`. `Parametros::saveParametro()` **rechaza esa clave**.
- **Si falta o está rota es `null` con el motivo, nunca un 30 de respaldo** (`ventanaDesdeValor()`): no se leen vencidos, y la pestaña y el tablero avisan como **crítico**, diciendo dónde cargarla.
- **Fuera de la ventana el cheque sale solo, sin aviso**: ya no se puede depositar. Si tenía fecha asignada, deja de aplicar y queda en el historial.

### La fecha de acreditación interna

**Sólo a vencidos en cartera.** Se acepta (`Echeqs::validarFechaAsignada()`, pura):

| Regla | Si no |
| --- | --- |
| De **mañana** en adelante | *"…tiene que ser de mañana en adelante: el 21/10 es hoy"* o *"…ya pasó"* |
| Hasta el **menor** entre el fin del horizonte y el último día en que el cheque se muestra (su fecha + N) | El rechazo dice **qué tope aplicó**: *"…es posterior al 14/11, el último día en que el cheque se sigue mostrando (su fecha más 30 días)…"* o *"…es posterior al fin del horizonte (30/09)…"* |
| **Un día hábil** | *"El 24/10 es sábado: elegí un día hábil"*, *"El 20/11 es feriado: elegí un día hábil"*. **La fecha asignada nunca se corre sola** |

Un mes que no está en `RO_T_CALENDARIO` usa el respaldo de lunes a viernes, y el mensaje de éxito lo avisa.

**El tope de la ventana evita una fecha que no puede llegar**: sin él, un cheque podía salir de la ventana antes de su fecha asignada, y esa plata se habría proyectado en un día al que nunca llega.

**Cómo se carga:**

- **De a uno**, en la celda **Acredita** del vencido, con un selector de fecha. Sólo con permiso de edición de Echeqs (`['echeqs', null]`); sin permiso se ve la fecha o un guion. Vaciar la celda no deshace: eso va por la barra, con confirmación.
- **En masa**, con **Asignar fecha** en la barra de selección: se habilita sólo si **todos** los seleccionados son vencidos en cartera. El diálogo dice cuántos son y por cuánto, cuántos ya tenían fecha —se reemplazan—, y pide la fecha y una observación opcional.
- **Deshacer fecha**, en la barra, con confirmación: se habilita sólo si todos tienen una fecha vigente —también la pasada—. Baja lógica.

**Todo o nada**, en una transacción. El servidor vuelve a validar contra la lectura de la base —la cartera y los depositados, resueltos con la regla de la pantalla—: con un cheque que no es vencido, uno depositado o uno que no está en la lectura (`validarSeleccionVencidos()`), o una fecha que no sirve para alguno —el tope es de cada cheque— (`validarFechaParaSeleccion()`), no se graba ninguno y el mensaje dice cuál y por qué. **Del navegador viajan sólo los ids, la fecha y la observación.**

### Qué pasa con el tiempo

| Caso | Qué pasa |
| --- | --- |
| La fecha llega **hoy** y el cheque sigue en cartera | Cae en la columna de hoy, como un cheque fechado hoy |
| La fecha **ya pasó** y el cheque sigue en cartera | `FECHA_PASADA`: no suma, y la pestaña y el tablero avisan con nivel **atención**: *"N cheque(s) vencidos por $ X tenían fecha de acreditación ya pasada y siguen en cartera: reasignales la fecha"*. Se ve siempre, aunque el switch esté apagado, y en la tarjeta cuenta entre los sin fecha |
| Tesorería **lo deposita** en Tango | Pasa a `A` y entra en *Echeqs depositados* con la regla de las 48 hs. La fecha asignada deja de aplicar sin aviso y queda en el historial |
| Se **rechaza o se anula** | Sale solo |
| Sale de la **ventana** | Sale solo, sin aviso |

### El modelo: `RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION`

| Columna | Qué guarda |
| --- | --- |
| `ID` | `IDENTITY`, PK: un cheque puede tener varias fechas en el tiempo |
| `ID_SBA14` | El cheque. **Sin FK contra `dbo.SBA14`**: es una tabla de Tango |
| `FECHA_ACREDITACION` | La fecha asignada |
| `OBSERVACION` | Opcional, 200 |
| `VIGENTE`, `ID_REEMPLAZA` | La vigente, y la que reemplaza al reasignar |
| Las seis de auditoría | Alta, modificación y baja |

**Una sola vigente por cheque**: índice único filtrado `WHERE VIGENTE = 1`. Reasignar da de baja la vigente e inserta la nueva en la misma transacción; dos pestañas al mismo tiempo chocan contra el índice y la segunda vuelve atrás entera.

### En el tablero

| Caso | Serie |
| --- | --- |
| Vencido con fecha | `A_COBRAR` en la fecha asignada, por el bruto. Fuera del eje —se achicó el horizonte—, a `fuera_horizonte` con el aviso de siempre |
| Vencido sin fecha, o con la fecha pasada | **Ninguna**, ni `A_COBRAR_TODO`. Aviso de **atención**: *"N cheque(s) vencidos en cartera sin fecha de acreditación por $ X no se proyectan. Asignales una fecha en Ingresos › Echeqs"* |
| Vencido excluido sin fecha | Ninguna, **y no cuenta en el aviso**: ya se decidió que esa plata no entra. Tampoco en el aviso de excluidos, que cuenta lo que está en `A_COBRAR_EXCLUIDOS` |
| Vencido excluido con fecha | `A_COBRAR_EXCLUIDOS` en esa fecha |

**La partición sigue cerrando** columna por columna: un vencido sin fecha no está en ninguna parte ni en el total.

**La lectura agregada va partida en dos, sobre el mismo universo**: lo de hoy en adelante sigue en el `GROUP BY` por fecha y excluido; lo anterior a hoy —los que todavía caen hoy por el corrimiento y los vencidos, cada uno con su fecha— va fila por fila y se cruza en PHP con las fechas asignadas. Las dos partes pasan por `resolverFechaCartera()`, y la prueba con datos reales fija que juntas suman lo mismo que la grilla.

**El universo es uno solo**: la lectura fila por fila, la agregada y `getExclusionesEnCartera()` usan el mismo `WHERE` (`sqlUniversoCartera()`), con la centinela `1800-01-01` afuera **explícitamente** —`>= hoy` ya no la filtra—. Venta Cobrada Anticipada no cambia de universo: sigue con `>= hoy`.

### Sin el script

Los vencidos **no se leen** —la ventana tampoco existe—, la pestaña avisa qué script falta (atención) y **el tablero no dice nada**. Todo lo demás funciona como antes, incluido el corrimiento. Con la tabla y la ventana rota o faltante, el aviso es **crítico** en la pestaña y en el tablero.

---

## Qué es un depósito

Un cheque de `dbo.SBA14` es un depósito si cumple **todo** esto (`Echeqs::esDepositoTango()`, puro). **El cliente no se mira**: ver *Todos los cheques, sin filtro de cliente*.

| Condición | Por qué |
| --- | --- |
| `ESTADO = 'A'` | Salió de cartera. Uno que después se rechaza o se anula pasa a `R` o `X` y deja de leerse solo |
| `T_COMP_SAL = 'BDE'` | Salió por una boleta de depósito. Un `O/P` es una orden de pago: el cheque se endosó a un proveedor, no entra al banco y no se toca |
| `FECHA_SAL` real | No la centinela `1800-01-01` |
| **La cuenta que la boleta debita es un banco** | `dbo.SBA01.TIPO = 'B'`. Ver abajo |

**Se identifica por el código `BDE` y no por `ID_SBA02_SAL`.** Las dos columnas coinciden siempre —las 27.627 filas con `BDE` tienen `ID_SBA02_SAL = 11`, "BANCO: DEPOSITO" en `dbo.SBA02`—, pero el código es la misma clave con la que se lee la boleta en `dbo.SBA05` y un id interno puede cambiar entre instalaciones.

### La cuenta de la boleta, y por qué `TIPO_SAL` no decide nada

Una BDE tiene en `dbo.SBA05` **una línea al debe** contra la cuenta destino y una al haber contra **116910 CHEQUES ELECTRONICOS**. Verificado el 16/10/2026 sobre 120 días de boletas: ninguna tiene dos líneas al debe.

- **`TIPO_SAL = 'D'`** trae la cuenta también en `CTA_DESTIN`.
- **`TIPO_SAL = 'O'`** deja `CTA_DESTIN` en 0, pero su boleta debita una cuenta igual que la de un `D`. En 90 días eran la mayoría: 235 de 358. La boleta `02005000040735` de la muestra debita 110500 BCO.RIO CTA.CTE. $.

**Lo que separa los casos es la cuenta debitada**, no el tipo. BDE de franquicias y locales en estado `A`, 90 días al 16/10/2026 —medido antes de sacar el filtro de cliente—:

| Cuenta debitada | `SBA01.TIPO` | Cheques | Bruto | Qué es | ¿Depósito? |
| --- | --- | --- | --- | --- | --- |
| 110500 RIO, 110800 FRANCES, 110100 GALICIA, 110600 ICBC | `B` | 152 | $ 1.073 M | *"DEPOSITO ECHEQS A BANCO …"* | **Sí** |
| 110530 BCO. RIO - CTA COMITENTE 7786 | `O` | 206 | $ 677 M | *"ENDOSO A SANTANDER VALORES"*; el último, el 14/09 | No: va a la cuenta comitente, no acredita en el saldo de Interbanking |
| 100100 CAJA FABRICA | `O` | 0 en `A` | — | *"DEVOLUCION ECHEQ …"*: terminaron todos en `R` | No |

**Sin la línea de la boleta, el cheque cuenta como depósito** con un aviso de atención que nombra la boleta: es un dato que falta, no uno que diga que no entra al banco.

**Un descuento de echeqs cuenta como depósito, con la regla general.** La boleta `…040556` del 19/08 a BBVA (*"BBVA DESCUENTO DE ECHEQS"*, 44 cheques por $ 328,6 M con fecha de noviembre) es una cuenta bancaria: se proyecta a las 48 hs de la boleta, con IIBB + SICREB. El tooltip muestra las dos fechas —boleta y cheque—; si el banco acreditó antes, sale por el extracto de Interbanking o se marca *Ya acreditó*. Decisión de Tesorería.

**La exclusión es de cartera.** Un cheque excluido que después se deposita en Tango **se proyecta igual**: el depósito es el hecho más nuevo y dice que la plata entra. La exclusión queda en su historial, sin efecto. Si después se rechaza, pasa a `R` y sale solo.

---

## Dónde cae un depositado y por cuánto: una sola regla

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
| Acreditó **antes de hoy** por plazo | — | Deja de leerse |
| **El extracto de Interbanking trae su crédito** | `ACREDITADO` (por extracto) | No se proyecta aunque falten días. Se lista con la marca *acreditado (Interbanking)* hasta su fecha de acreditación. Ver *La acreditación según el extracto* |
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
WHERE s.ESTADO = 'A' AND s.T_COMP_SAL = 'BDE'
  AND s.FECHA_SAL >= hoy − plazo × DiasHabiles::MAX_CORRIMIENTO
```

Una boleta anterior a esa fecha ya acreditó seguro: cada día hábil del plazo puede correrse hasta `MAX_CORRIMIENTO` días (`Echeqs::rangoDepositos()`, mismo criterio que `rangoCalendarioAcreditacion()` de Saldos). El 16/10/2026 eran 225 cheques en 60 días. Sin plazo se lee un solo tramo de corrimiento, para poder avisar cuántos quedaron sin proyectar.

**Las boletas y las cuentas se cruzan en PHP**, por la regla del repo: cuatro lecturas chicas —los cheques de `SBA14` (con el `LEFT JOIN` de `BANCO` que ya usa la cartera, para el nombre del emisor), la línea al debe de cada boleta en `SBA05` y su cuenta en `SBA01`, los créditos del extracto de Interbanking con el número de esos cheques, y las marcas *ya acreditó*—. La consulta aplica el mismo filtro que `esDepositoTango()` como optimización; la regla que decide es la de PHP.

**La cartera volvió a ser sólo `ESTADO = 'C'`**, sin unión con ningún depósito: un cheque depositado no está en cartera en Tango. Ver `sqlCartera()`.

### La acreditación según el extracto de Interbanking

`BI_T_EXTRACTOS_INTERBANKING` (en `central`, la carga BI) trae los movimientos de las cuentas bancarias de **los últimos 60 días**. Un cheque depositado **sale de *Echeqs depositados*** cuando el extracto trae su crédito (`Echeqs::creditoExtracto()`, puro):

| Condición | Por qué |
| --- | --- |
| `TIPO_MOV = 'C'` | Un crédito. El débito con el mismo número es la retención del banco |
| `NRO_COMPROBANTE` = el número del cheque | Es como lo informa Galicia |
| `IMPORTE` = el bruto, al centavo | El banco acredita el cheque entero y debita la retención aparte |
| `FECHA_OPERACION` entre la boleta y hoy | Un crédito anterior al depósito es de otra cosa; uno posterior a hoy no puede decir que ya acreditó |

Si hay varios, vale el más viejo, y **un movimiento acredita un solo cheque**: se asignan por fecha de boleta.

**Se lee al calcular, no graba marcas.** No hay proceso ni job: la pestaña y el tablero miran lo último que cargó BI. Un cruce equivocado no se deshace a mano: se corrige en la regla. **El extracto no se escribe nunca.**

**Sólo número + importe, decisión de Tesorería.** Verificado el 16/10/2026 sobre los depósitos desde el 01/09:

| Banco | Qué trae el extracto | ¿Cruza? |
| --- | --- | --- |
| Galicia (007) | Un crédito por cheque con su número y el bruto (*"GESTION DE ECHEQ"* u otras descripciones), y un débito aparte por la retención | **Sí**: casi todos, con 0, 1 o 3 días de demora |
| Francés (017) | Un crédito por cheque por el bruto (*"DEP.CH.24 HS"*, *"DEPOS.CHQ.48HS"*), pero con un número del banco | No: no se cruza por importe solo |
| Santander (072) | Sólo el total de créditos del día (`CRED_TOTAL_DIA`), sin el detalle de los echeqs | No |
| ICBC (015) | — | No apareció ninguno |

Lo que no cruza sigue **por plazo** o con la marca **Ya acreditó**.

**Que un cheque no tenga crédito no dice nada**: el extracto guarda 60 días y no todos los bancos detallan los echeqs. Sólo un crédito encontrado lo da por acreditado. La lectura de depositados mira `plazo × 30` días hacia atrás —60 con el plazo de hoy—, así que un crédito que el extracto ya borró es de un depósito que vence por plazo igual.

**Sin la tabla**, la pestaña y el tablero siguen andando con un aviso informativo, y los depositados van por plazo o con la marca.

**Ejemplo:** el cheque 40664466 (MACIRU, boleta del 07/10 a Galicia) tiene su crédito de $ 572.698,57 el 08/10: habría salido ese día, en lugar del 09/10 por plazo. Galicia le debitó aparte $ 4.008,88 (*"DEP CHEQ 40664466"*), un 0,7%, menos que el 3,1% de las alícuotas cargadas: conviene revisar si IIBB y SICREB se retienen por otro lado.

---

## Ya acreditó

Cuando Tesorería ve que un cheque ya acreditó antes de las 48 hs, lo marca y sale de *Echeqs depositados*. Así no se cuenta dos veces con el saldo de Interbanking, que ahora se actualiza cada hora. **Es para lo que el extracto no confirma**: Francés, Santander, ICBC.

- **Dónde:** en la barra de selección de *Cheques en Cartera*, **Ya acreditó** y **Deshacer ya acreditó**. Se dibujan sólo con permiso de edición de Echeqs (`['echeqs', null]`), y sólo se muestran si el script de la marca está corrido.
- **Qué se puede marcar:** sólo depositados que **todavía se proyectan**, uno o varios, **todo o nada**. Uno en cartera, uno ya marcado, uno que ya acreditó según el extracto, uno que acredita hoy, uno sin datos o uno que no está en la lectura —endosado, rechazado, vencido— se rechazan diciendo cuál y por qué. El servidor vuelve a validar contra la lectura de la base (`Echeqs::validarSeleccionAcreditado()`).
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

### `ORIGEN = 'INTERBANKING'`, previsto

La tabla se diseñó para que un proceso grabara ahí las acreditaciones de Interbanking. **Con el extracto leído directo no hace falta**: el crédito no es una marca, y no pasa por esta tabla. `ORIGEN = 'INTERBANKING'` queda en el `CHECK` por si algún día existe ese proceso. **La regla no depende del origen**: `resolverDeposito()` sólo pregunta si hay una marca vigente.

**Un proceso así no podría volver a grabar una `REFERENCIA` que ya existe, aunque esté dada de baja.** Si alguien deshace a mano una marca de Interbanking, el deshacer se respeta. Lo asegura el índice único de la referencia, y lo dice el encabezado del script.

---

## El tablero

### La partición

| Serie | Qué trae | Fila |
| --- | --- | --- |
| `A_COBRAR` | La cartera (`ESTADO = 'C'`) **cobrable**, en **bruto**, en su **día hábil** —o, un vencido, en su fecha asignada— | *Echeqs en cartera* |
| `A_COBRAR_EXCLUIDOS` | Lo excluido a mano, en bruto, con la misma regla de fecha | — |
| `DEPOSITADOS` | Los depositados en Tango que se proyectan, en **neto** y en la fecha de **acreditación** | *Echeqs depositados* |
| `A_COBRAR_TODO` | Las tres juntas: lo que la cartera aporta al flujo | — |

```
A_COBRAR + A_COBRAR_EXCLUIDOS + DEPOSITADOS = A_COBRAR_TODO
universo en bruto = A_COBRAR_TODO + retención de los depositados
```

**Cada cheque está en una sola parte**: las dos primeras salen de la cartera (`C`) y la tercera de los depositados (`A`), y un cheque no puede estar en los dos estados. Un vencido sin fecha **no está en ninguna** ni en el total, a propósito: ver *Los cheques vencidos en cartera*. Las partes cierran contra el total **columna por columna**; lo fijan las pruebas, también contra los datos reales.

**La cartera de hoy en adelante sigue agregada** (`getEcheqsCarteraTotales()`, un `GROUP BY` por fecha y excluido, corrido fecha por fecha al día hábil); **lo anterior a hoy va fila por fila**, porque cada vencido tiene su fecha asignada. **Los depositados van fila por fila**, porque la acreditación y el neto se calculan en PHP con el calendario y las alícuotas; son pocos. `EcheqsProvider::itemsDeDepositados()` los convierte en ítems del reparto sin recalcular nada.

**La retención viaja como escalar de `DEPOSITADOS`** —`bruto` y `retencion`, como `fuera_horizonte`—, y no como series: una serie se puede elegir para una fila, y `DEPOSITADOS_BRUTO` al lado de `DEPOSITADOS` contaría dos veces el mismo cheque. Los escalares se auditan en `EcheqsProvider::repartir()`; el motor los descarta al normalizar la serie.

**El validador no cambió**: `componentes` declara `A_COBRAR_TODO => [A_COBRAR, A_COBRAR_EXCLUIDOS, DEPOSITADOS]`.

### Lo demás

- Una acreditación **fuera del eje** va a `fuera_horizonte`, en `DEPOSITADOS` y en el total, y el tablero avisa.
- Los avisos de los depositados —sin plazo, sin hábil, sin alícuotas, calendario faltante, boleta sin cuenta— salen en el tablero y en la pestaña con su nivel.
- Si hay depositados que se proyectan y **ninguna fila activa muestra `ECHEQS → DEPOSITADOS`**, la pestaña lo avisa como **crítico**: esa plata no está en ninguna fila. Se pregunta por la serie y no por el código de la fila (`Echeqs::filaDepositadosActiva()`).
- **Venta Cobrada Anticipada no depende del depósito**: el neteo va por tilde, no por estado ni por depósito. Un cheque pre-chequeado que se deposita sigue neteando la venta en el día hábil del cheque —el mismo corrimiento que la cartera—.
- Los avisos de la cartera —vencidos sin fecha o con la fecha pasada, ventana rota, calendario faltante, sin hábil— salen en el tablero y en la pestaña con su nivel. El de *falta el script de los vencidos* sale sólo en la pestaña.

---

## La grilla de Cheques en Cartera

**Los depositados se ven en la misma grilla que la cartera**: el controller junta las dos lecturas (`getEcheqsCartera()` y `getDepositadosTango()`) y las ubica en el eje con `Echeqs::ubicarEnEje()`, la misma regla del proveedor.

| Qué | Cómo |
| --- | --- |
| **Dónde cae el importe en el eje** | Uno de cartera, en su día hábil —o, un vencido, en su fecha asignada— y por el bruto (`resolverFechaCartera()`); un depositado que se proyecta, en su acreditación y por el neto; un vencido sin fecha, uno acreditado o uno sin datos, en ninguna columna (`FECHA_EJE` null, `IMPORTE_EJE` 0). El eje se arma con `FECHA_EJE` / `IMPORTE_EJE` |
| **Fecha de pago** | La fecha del cheque, la escrita. Conserva su nombre: es la que ancla el orden guardado |
| **Acredita** | **Dónde cae el importe**: el día hábil de uno de cartera —la fecha corrida, si se corrió—, la acreditación de un depositado, la fecha asignada de un vencido. En un vencido es un **selector de fecha**, sólo con permiso de edición; sin permiso, la fecha o un guion |
| **Retención y Neto** | **Siempre visibles**, vacías —no en cero— en los de cartera. Aparecer y desaparecer movería los índices de las columnas fijas, el orden guardado y lo que baja el export |
| **Marcas** | **corrida** (cae en el hábil siguiente; sólo en los vigentes), **vencido · sin fecha**, **vencido · fecha asignada**, **fecha asignada pasada**, **depositado** (se proyecta), **acreditado (por plazo)** (acredita hoy), **acreditado** (marcado) y **depositado, sin proyectar** (le falta un dato) |
| **Tooltip de la marca** | *Corrida*: de qué día a cuál y por qué. *Vencido*: la fecha del cheque, los días que lleva vencido y hasta cuándo se sigue mostrando; la fecha asignada con quién la dio, cuándo y la observación, y las anteriores con quién las dio de baja. *Depositado*: la boleta de Tango —número y fecha—, la cuenta donde se depositó con su descripción, `TIPO_SAL` y `CTA_DESTIN`, el banco emisor, la fecha del cheque, la acreditación con el plazo, el bruto, la tasa con su desglose y el neto; si está marcado, quién, cuándo, el origen y la observación, y las marcas deshechas |
| **Ocultar depositados** | Al lado de *Ver excluidos*. **Por defecto se ven**: siguen entrando al cashflow hasta que acreditan. Esconde también los acreditados y los sin proyectar; sus avisos salen siempre |
| **Ver vencidos sin fecha** | Al lado, **apagado por defecto**, y dice cuántos esconde y por cuánto. **Esconde sólo los vencidos sin fecha**: los de fecha asignada se ven siempre —suman— y los de fecha pasada también —piden reasignarla—. **Lo escondido gana sobre la búsqueda**, como en *Ocultar depositados*: si el buscador coincide con uno escondido, dice *"N vencidos sin fecha coinciden: prendé el switch"* |
| **Tarjetas** | Las tres de siempre muestran **la cartera cobrable** —lo mismo que *Echeqs en cartera*—, **con los vencidos con fecha adentro**, y el rótulo lo dice. La cuarta, **los depositados que se proyectan** con bruto, retención y neto. La quinta, **Vencidos en cartera** —sin los excluidos—: el bruto, cuántos y cuánto tienen fecha (suman) y cuántos y cuánto no. Las restas las hace PHP |
| **Pie** | La fila *TOTALES* suma lo que se ve, y debajo se abre en *En cartera* —con un renglón indentado y en cursiva, *↳ de esos, vencidos con fecha · ya sumados arriba*, para que no parezca sumado dos veces—, *Vencidos sin fecha · no suman*, *Depositados*, *Acreditados · no suman*, *Depositados sin proyectar · no suman* y *Excluidos · no suman* |
| **Selección** | La barra dice cuántos, cuánto, de qué banco y cuántos vencidos. *Excluir* no se habilita con un depositado —ya salió de cartera— y sí con un vencido; *Ya acreditó* sólo si todos se proyectan; *Asignar fecha* sólo si todos son vencidos; *Deshacer fecha* sólo si todos tienen fecha; *Deshacer ya acreditó* sólo si todos están marcados |
| **Avisos** | Los de la cartera, los de los depositados, el de la fila del tablero y los de los scripts entran al panel plegable de la pestaña |
| **Exportar** | Sale del DOM vivo: baja lo que se ve, con Acredita —la fecha corrida o la asignada; el selector baja como `dd/mm/aaaa`— y las marcas |

---

## Parámetros › Cheques

La sub-pestaña **Pre-chequeado pasó a llamarse Cheques**. Cambió sólo el nombre visible: **el código de módulo sigue siendo `PRECHEQUEADO`**, que es la clave de los permisos ya asignados en apps y la de la carga diferida. Aloja el maestro de **venta cobrada anticipada** y, arriba, la sección **Depósito de cheques** —va primero porque mueve plata del tablero—.

### El plazo de acreditación

`echeqs_dias_acreditacion_deposito` en `RO_T_CASHFLOW_PARAMETROS`, módulo `PRECHEQUEADO`, grupo `DEPOSITO`. **Días hábiles bancarios** después de la boleta de depósito: un entero de **1 a 30**.

- **Tiene su propio camino de guardado** (`Echeqs::guardarPlazo()`, acción `savePlazoDepositoEcheq`), que valida. `Parametros::saveParametro()` **rechaza esa clave**: guarda cualquier texto, y un plazo de cero o de "dos" dejaría a los depositados sin proyectar.
- **El tope de 30 no es del banco**: es el de un error de tipeo, y coincide con `DiasHabiles::MAX_CORRIMIENTO`.
- **Un plazo que falta o está roto es `null` con el motivo, nunca un 2 de respaldo** (`Echeqs::plazoDesdeValor()`): los depositados no se proyectan y avisan como crítico.
- **Cambiarlo mueve también los que todavía no acreditaron**: la fecha se calcula al leer, desde la boleta.

### Los días hacia atrás para cheques vencidos

`echeqs_dias_vencidos_cartera`, en la misma sección, debajo del plazo: *"Días hacia atrás desde la fecha del cheque en que un cheque vencido en cartera se sigue mostrando"*. Un entero de **1 a 30**, con su propio camino de guardado (`Echeqs::guardarVentana()`, acción `saveDiasVencidosEcheq`); `saveParametro()` rechaza la clave. Sin el script, el campo queda apagado y la sección avisa qué correr. Ver *Los cheques vencidos en cartera*.

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
- **qué es un depósito**: BDE `A` a un banco sí; `O/P`, cartera, `R`, `X`, la centinela, sin cliente u otro prefijo, la cuenta comitente y la caja no; un mayorista sí; `TIPO_SAL = 'O'` sí; sin la línea de la boleta, sí con aviso;
- **la fecha y el importe**: viernes → martes, el feriado en el medio, la fecha del cheque que no interviene, el neto con la alícuota vigente a la acreditación, un cambio de alícuota o de plazo que se ve sin recalcular, el calendario faltante, sin hábil, sin alícuotas y sin plazo;
- **cuánto se proyecta**: mañana sí, hoy y antes no, marcado no, `INTERBANKING` igual que `MANUAL`, deshecha la marca vuelve;
- la lectura, los avisos y el excluido que se deposita;
- las consultas, y que nada consulta `RO_T_CASHFLOW_ECHEQ_DEPOSITO`;
- el circuito eliminado y sus textos;
- la partición del proveedor columna por columna, `A_COBRAR_TODO` que cierra, y **la pestaña y el proveedor que suman lo mismo**;
- *Ya acredito*: todo o nada sobre los que se proyectan, un solo vigente, deshacer, lo que se graba, los permisos y el script;
- **el extracto**: número e importe iguales cruzan; otro importe, otro número, un crédito anterior a la boleta o posterior a hoy, o un débito, no; con dos créditos gana el más viejo; un movimiento no se usa dos veces; el crédito saca al cheque aunque falten días y pesa más que una marca; *Ya acreditó* lo rechaza; el proveedor no lo suma; sin la tabla, aviso.

`tests/test_echeqs_vencidos.php`, sin base salvo las escrituras sobre una `#temporal`:

- **el script y la ventana**: la tabla con su índice único filtrado, el parámetro en 30 sin pisarlo, de 1 a 30, `null` sin respaldo, `saveParametro()` que rechaza la clave, y los permisos;
- **el corrimiento**: sábado y domingo → lunes, feriado → el hábil siguiente, viernes feriado → lunes, un hábil no se corre, el motivo —un feriado en sábado es fin de semana—, el respaldo con aviso, sin hábil en el tope queda en su fecha con aviso crítico, el tope del eje, y que no se aplica a depositados ni a fechas asignadas;
- **el sábado leído el lunes** cae hoy (`VIGENTE`), leído el martes es `VENCIDO_SIN_FECHA`, y un sábado con lunes feriado leído el lunes cae el martes;
- **la ventana**: el de ayer y el de hace N días entran, el de hace N + 1 y la centinela no, el de hoy no es vencido;
- **la fecha a asignar**: mañana hábil sí; hoy, ayer, un sábado, un feriado, más allá del horizonte o del último día a la vista no, cada uno con su motivo y su tope; un mes sin calendario usa el respaldo con aviso;
- **todo o nada**: con un vigente, un depositado o uno que no está en la lectura no se graba ninguno, y una fecha que no sirve para uno no sirve para ninguno;
- **la fecha en el tiempo**: igual a hoy suma hoy, anterior no suma y avisa, fuera de la ventana sale, depositado entra por las 48 hs;
- **el reparto**: con fecha a `A_COBRAR`, sin fecha a ninguna serie con su aviso, excluido sin fecha a ninguna y sin aviso, excluido con fecha a `A_COBRAR_EXCLUIDOS`; la partición cierra columna por columna, y **la pestaña y el proveedor suman lo mismo**;
- **sin el script**: no hay vencidos, aviso de atención sólo en la pestaña con el nombre del script; con la ventana rota, crítico en los dos;
- **las escrituras**, sobre una `#temporal` con el cuerpo del script: reasignar deja un solo vigente que apunta al reemplazado, deshacer vuelve a sin fecha y conserva el historial, y el índice frena una segunda vigente. La subclase de la prueba se verifica a sí misma: no nombra ninguna tabla real;
- **Venta Cobrada Anticipada y el neteo**: el cheque y su neteo en la misma columna corrida, la misma que en *Echeqs en cartera*, y el neteo sin calendario no se reparte.

`tests/test_echeqs.php` verifica la partición **contra los datos reales**: las tres partes suman el total, los depositados que se listan son BDE a un banco sin vencer, los acreditados por el extracto cruzan por número, importe y fecha, la agregada y la fila por fila suman lo mismo, ningún vigente cae antes de hoy, los vencidos leídos están en la ventana y en cartera y suman sólo con fecha, la sub-pestaña y el neteo ponen cada marcado en la misma columna, y el neteo no deja nada en un sábado ni en un domingo. `tests/test_tablas_controles.php`, el cableado: no quedan botones ni acciones de *Depositar*, y están *Ya acreditó*, su acción y su permiso; el switch *Ver vencidos sin fecha*, la celda Acredita editable sólo con permiso, *Asignar fecha* y *Deshacer fecha* con sus acciones y permisos, la tarjeta, el pie y las marcas; y el campo de la ventana en *Parámetros › Cheques*. `tests/test_dias_habiles.php` fija `habilesDespues()`, y `tests/test_retenciones.php` la clase compartida.

Las escrituras de la marca se verificaron contra la base con una `#temporal` con el cuerpo del script, sin tocar tablas `dbo`: marcar, rechazar la segunda marca, el índice de vigentes, deshacer con su historial, la referencia dada de baja que no vuelve a entrar y el `CHECK` del origen.

---

## Archivos

```
sql/cashflow_echeqs_deposito.sql                Alicuotas de cheques y plazo (la tabla de depositos, en desuso)
sql/cashflow_estructura_echeqs_depositados.sql  La fila Echeqs depositados
sql/cashflow_echeqs_acreditado.sql              La marca "ya acredito"
sql/cashflow_echeqs_vencidos.sql                La fecha de acreditacion de los vencidos y la ventana
Class/Retenciones.php                           La tasa de retencion, compartida con Cob. Electronicos
Class/DiasHabiles.php                           siguiente() y habilesDespues(): el corrimiento y el plazo
Class/Echeqs.php                                Donde cae un cheque de cartera (resolverFechaCartera, correrAHabil),
                                                los vencidos y su fecha, que es un deposito, la lectura, la marca
Class/Providers/EcheqsProvider.php              La particion: A_COBRAR, EXCLUIDOS, DEPOSITADOS, TODO
Class/Ventas.php                                repartirNeteo(): el neteo, con el mismo corrimiento
Class/CashflowRegistry.php                      La serie DEPOSITADOS y su componente
Class/Parametros.php                            Sub-pestana Cheques: seccion deposito
Controller/EcheqsController.php                 getEcheqsCartera, getEcheqsPrechequeado, marcarAcreditados,
                                                deshacerAcreditados, asignarFechaVencidos, deshacerFechaVencidos
Controller/ParametrosController.php             savePlazoDepositoEcheq, saveDiasVencidosEcheq, addAlicuotaEcheq,
                                                bajaAlicuotaEcheq
Tabs/echeqs.php, Js/Ingresos-Echeqs.js          La grilla
Js/notificaciones.js                            pedirFecha(): con max y observacion
Tabs/parametros_prechequeado.php                Parametros > Cheques
Js/Parametros-Prechequeado.js
tests/test_echeqs_deposito.php, tests/test_echeqs_vencidos.php, tests/test_retenciones.php
```
