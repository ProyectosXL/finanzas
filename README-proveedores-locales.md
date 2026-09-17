# Módulo Proveedores Locales — cuentas a pagar, fecha de pago y conciliación

Las cuentas a pagar del mercado local: cuánto se debe, cuándo vence, cuándo se piensa pagar y —después— cuándo se pagó de verdad.

Rama: `feature/proveedores-locales-pagos-reales`

---

## La idea en una línea

**Tango dice cuánto se debe y cuándo vence; acá se decide cuándo se paga, y esa decisión es lo único que saca a la deuda vencida del primer día del eje.**

```
Tango (CPA04 + CPA54 + CPA01, con las imputaciones de CPA05)
        │  una fila por VENCIMIENTO, no por comprobante
        ▼
Class/Proveedores.php  ──────────────┐
        │                            │  resuelve la fecha con la que entra al eje:
        │                            │    fecha cargada → vencimiento → emisión + plazo
        ├─ Class/ProveedoresCategorias.php
        │     el maestro: qué es cada proveedor, y cómo se le paga
        ▼
   Pestaña "Proveedores Locales"      Cuentas a Pagar / Importar / Maestro
        │
        ▼
   ProveedoresProvider → fila "Cuentas a Pagar Locales" del tablero
```

---

## Para pasar a producción

Contra `central`, en cualquier momento:

```sql
-- 1. sql/cashflow_prov_locales.sql
-- 2. sql/cashflow_prov_locales_collation.sql      (antes de la primera importación)
-- 3. sql/cashflow_prov_locales_maestro_manual.sql (para cargar el maestro a mano)
-- 4. sql/cashflow_prov_locales_forma_por_factura.sql (forma de pago por factura)
-- 5. sql/cashflow_prov_locales_excluir_factura.sql   (excluir facturas sueltas)
```

El quinto va **después** del cuarto: necesita que `FECHA_PAGO` ya sea nullable, y si no lo es aborta diciéndolo. Las filas quedan con `EXCLUIDA = 0`, así que el tablero no se mueve.

El cuarto agrega `FORMA_PAGO_CRONOGRAMA` y hace `FECHA_PAGO` nullable. Las filas que ya están quedan con el override en `NULL` —*"usa la forma del maestro"*—, así que el tablero no se mueve. **Sin él el listado se lee igual** y la columna *Cronograma* muestra la del maestro en vez de un desplegable que fallaría al guardar.

El tercero agrega `ORIGEN` al maestro y marca como `IMPORT` lo que ya está, que es lo que es. **Sin él el maestro se lee igual**: lo que no se puede es cargarlo a mano, y la pestaña lo dice con el script al lado en vez de dibujar un formulario que después falla.

Crea `RO_T_CASHFLOW_PROV_LOCALES_CATEG` (el maestro) y `RO_T_CASHFLOW_PROV_LOCALES_PAGO` (las fechas de pago), y renombra la fila del tablero. Es reejecutable.

**Sin él la pantalla no falla: avisa.** El listado de cuentas a pagar funciona igual —sale de Tango— pero todo se proyecta al vencimiento y no se puede cargar ninguna fecha.

`sql/_referencia_tango_pendientes.sql` **no se corre**: es la consulta original de Tango, guardada como referencia. El guión bajo la saca de la lista de scripts.

---

## La consulta, y por qué se puede confiar en ella

**Reproduce a Tango al centavo.** Aplicándole sus mismos filtros —sólo lo no vencido, sin excluir el exterior— da exactamente sus **158 filas** y sus **$521.858.835,68**.

Esa verificación es lo único que permite afirmar que el número del tablero es el mismo que el del reporte del que salió. Sin ella, "depuré la consulta de Tango" es una intención.

### Qué se sacó de la original

| Se sacó | Por qué |
| --- | --- |
| `CPA54.FECHA_VTO >= hoy` | **Hacía desaparecer todo lo vencido.** Es lo contrario de lo que el tablero tiene que hacer |
| `CPA57`, `CPA108` | Provincia y país: este listado no los usa |
| `SUCURSAL` y la subconsulta de `NRO_SUCURS` | Ídem |
| `GVA81` | Clasificación: ídem |
| `CPA_CONTACTOS_PROVEEDOR_HABITUAL` | Ídem |
| `LEFT JOIN EMPRESA ON 1 = 1` | Ídem |
| El `CASE 'BICLAUSU'` | Es una constante que Tango resuelve al generar la consulta: de las tres ramas sólo se evalúa una. El filtro queda escrito y comentado, no heredado de un `CASE` muerto |
| La columna `IMPORTETOTAL` de `TMP` | **Código muerto**: no se usa en el `SELECT` final, y además su `CASE` de signos está incompleto |

> El prompt decía que la consulta original *"tiene `SUM(...)` y no tiene `GROUP BY` — no corre"*. **Sí lo tiene**, al final, y corre: se ejecutó para obtener el número de referencia.

### Lo que sí hubo que copiar, y es lo más importante

`CPA05` **no guarda sólo pagos**: guarda todo lo que se imputa contra el comprobante, y no todo lo cancela.

| `T_COMP_CAN` | Efecto |
| --- | --- |
| `REC` | **resta** (aunque `CPA21` diga que `REC` es `'D'`) |
| `CPA21.CRE_DEB = 'D'` | **suma** — una nota de débito es deuda **nueva** |
| cualquier otro | **resta** (`O/P`, notas de crédito) |

`O/P` no figura en `CPA21` —es una orden de pago, no un comprobante de compras— así que cae en el `ELSE` y resta, que es lo correcto.

> **La rama de `REC` es código no ejercitado.** Se copió tal cual de la consulta de Tango, por fidelidad al origen, pero **no se pudo verificar con datos**: en `CPA05` hay 109.314 imputaciones y **ninguna** tiene `T_COMP_CAN = 'REC'` (son todas `O/P`, `NC*`, `ND*` o `AJU`). O sea que esa rama nunca se ejecuta hoy, y si algún día aparece un `REC`, será la primera vez que corra. Se deja porque Tango la declara explícitamente y sobreescribe el `CRE_DEB` de `CPA21` —que dice `'D'` para `REC`—, lo cual sólo tiene sentido si el caso existe en alguna instalación.

**Sin esa tabla de signos el pendiente da negativo.** Pasa de verdad:

```
DONNA DI DIO, FAC A0000500001731
  vencimiento          14.614.380,00
  O/P imputada        −14.614.380,00
  NDI imputada         +3.342.062,08   ← esto NO cancela, es deuda nueva
  pendiente real        3.342.062,08
```

Restando todo daba **−3.342.062,08**. Con los signos corregidos: **0 pendientes negativos** en las 522 filas.

### Una diferencia con Tango, a favor

Tango une `CPA04 ⋈ CPA54` por `(COD_PROVEE, T_COMP, N_COMP)`. Esa terna **no es única** en `CPA04`: hay 16 repetidas, todas del proveedor genérico `000000`. Hoy ninguna está pendiente, así que las dos formas dan lo mismo, pero acá se usa `ID_CPA04` —poblado en las tres tablas— que no depende de eso.

### Qué queda afuera

- **`COD_PROVEE LIKE 'Z%'`**: no son proveedores de sistema, son los del **exterior** (`ZE…`: China, Hong Kong, India, y `ZETASK`). Son 8.023 de los 9.344 millones pendientes totales, y **ya entran al tablero por `COMEX_PROV_EXT`**: sin el filtro se contarían dos veces.
- **`CPA01.CLAUSULA = 1`**: proveedores con cláusula de moneda extranjera. Es el criterio del *Total Pendiente (CTE)* de Tango, que es el pendiente en pesos. Hoy deja afuera $20.938 de un transportista local de 2022 y 2023, que además tiene el importe en moneda extranjera en cero: casi seguro un error de maestro.

**Total local: $1.415.279.515,19** en 544 vencimientos de 122 proveedores.

> **Todas las cifras de este archivo son una foto del 16/09/2026.** Salen de una base viva: cambian entre una corrida y la siguiente, y de hecho cambiaron mientras se escribía esto. Están para dar orden de magnitud y para poder decir *"esto no es teórico"*, no para cuadrar contra la pantalla.

---

## Lo vencido entra, sin techo de antigüedad

Cobranzas descarta lo vencido hace más de `Ingresos::DIAS_COBRO_VENCIDO` (180) días. **Acá no**, y la diferencia no es un descuido:

> Una factura vieja sin **cobrar** puede ser incobrable. Una vieja sin **pagar** sigue siendo deuda.

Con ese techo quedaban afuera **$346,8 millones — el 29%** del total, y no era basura administrativa: la mayor parte es un plan de cuotas vigente de un proveedor industrial.

**Pero no se apila en silencio.** El indicador *Vencido sin fecha* dice cuánto hay vencido **sin que nadie haya decidido cuándo se paga**: son **378 vencimientos por $965.189.059,14**.

Ese importe se dibuja en el primer día del eje porque no hay otro lugar donde ponerlo. **Eso no significa que se pague hoy**, y por eso el número está a la vista en rojo, con un filtro de un clic para aislarlo.

**La herramienta para redistribuirlo es cargar la fecha, no un filtro por antigüedad.** Verificado: cargarle fecha a 5 comprobantes bajó el vencido sin fecha de $839.609.418 a $525.591.802.

> **Consecuencia visible en el tablero:** hasta que se importe la planilla de pagos, el *Saldo Final* va a dar negativo los primeros días (−699 M el día 1). No es una proyección de caja: es esa deuda vencida apilada. La marca de columna negativa de la sección Cobertura la señala sola.

---

## La jerarquía de la fecha de pago

Escrita una sola vez, en `Proveedores::resolverFechaPago()`:

```
1. la FECHA DE PAGO CARGADA, si hay una para ese comprobante
2. si no, la FECHA DE VENCIMIENTO de Tango
3. si no hay vencimiento usable, EMISIÓN + PLAZO del maestro
4. si nada de eso alcanza, sin fecha
```

**El vencimiento va antes que el plazo, y no al revés.** El vencimiento es un dato de *esta* factura; el plazo es una costumbre del proveedor. Cuando los dos existen, el que la describe es el vencimiento.

El escalón 3 existe para cuando Tango trae su centinela `1800-01-01` en lugar de una fecha. Hoy no hay ninguno en `CPA54`, pero la consulta de referencia lo contempla, así que el código también: es una red, no la norma.

**Una fecha cargada no se reubica aunque esté vencida.** La cargó una persona; moverla a hoy sería pisar su decisión con una regla automática y mostrarle su propia carga en otra columna. Se marca vencida —eso es un hecho— y se dibuja donde está.

### El plazo del maestro no es un número

En la planilla los valores son `CONTADO`, `7 DÍAS`, `30 DÍAS`, `15 DÍAS`, `DEBITO`, y está **vacío en 765 de 1.223 filas (62%)**.

```
CONTADO   → 0      se paga el día de la factura
'N DIAS'  → N
DEBITO    → null   se debita solo; el plazo no dice cuándo
vacío     → null
```

**`null` no es lo mismo que `0`.** Cero es *"se paga hoy"* y se usa para calcular; `null` es *"este plazo no dice cuándo"* y hace caer al escalón siguiente.

---

## El maestro de proveedores

Tango sabe cuánto se le debe a cada proveedor. No sabe si es un taller, un alquiler, un impuesto o un socio. Eso vive en la hoja **Maestro proveedores** del Excel *Cronograma de Pagos*, y `RO_T_CASHFLOW_PROV_LOCALES_CATEG` es una **copia reimportable** de esa hoja, con su fecha de importación a la vista.

### Por qué una copia y no `CPA01.COD_RUBRO`

La columna existe y está **vacía en los 4.893 proveedores**; `CAMPOS_ADICIONALES` también (los 3.694 que lo tienen traen el XML vacío).

Se evaluó empezar a cargarla desde Tango y se descartó: **obligaría a administración a mantener dos maestros en paralelo**, y dos maestros en paralelo terminan discrepando. La planilla sigue siendo la fuente.

### Para qué sirve

1. **Para que el tablero pueda abrir la deuda por rubro.** Hoy la fila es una sola; cuando el maestro esté cargado, partirla en alquileres, impuestos, logística y mercadería es **configuración desde Parámetros**, no un refactor: el proveedor ya entrega cada comprobante con su rubro resuelto y expone una serie por rubro además del total.
2. **Para la forma de pago habitual**, que sirve de valor por defecto al importar pagos.
3. **Para el plazo**, que es el último escalón de la jerarquía de fecha.

### Rubro económico y rubro son dos columnas, no dos nombres de una

La planilla trae los dos, y clasifican en dos niveles distintos:

| | |
| --- | --- |
| **Rubro económico** | Es el que **abre la deuda por serie** en el tablero: `RUBRO_ALQUILERES`, `RUBRO_LOGISTICA`, `RUBRO_MERCADERIA`… Un proveedor sin este dato cae en `PAGOS_SIN_RUBRO` |
| **Rubro** | Clasifica **adentro** del económico. Es informativo: no arma ninguna serie, y viene vacío en buena parte de la planilla |

Las dos se ven en las dos solapas —*Maestro* y *Cuentas a Pagar*—, en el mismo orden. Antes *Cuentas a Pagar* mostraba una sola, así que el mismo proveedor se leía distinto según por dónde se lo mirara.

> Están en columnas separadas y no concatenadas en una celda a propósito: **el que decide tiene que poder leerse solo.** Juntarlos obligaría a saber cuál de los dos abre las series para interpretar la celda.

Cuando el económico falta se dice *sin clasificar* —el proveedor no está en el maestro, y eso es trabajo pendiente—; cuando falta el otro va un guion, porque está clasificado y esa columna simplemente vino vacía. Un mismo cartel para los dos casos mandaría a clasificar proveedores que ya lo están.

### Se puede cargar y editar a mano, y la planilla sigue mandando

> Esto es **nuevo**. Antes el maestro sólo se podía escribir importando el Excel.

El caso que lo pide es concreto y se ve en la propia pantalla: hay **20 proveedores con deuda por $17.011.478,01 que no están en el maestro**. Aparecen en Tango, nadie los agrega a la planilla, y su deuda queda sin clasificar. El control de faltantes ya los listaba; lo que no había era forma de resolverlos sin volver al Excel.

**Alta y edición son la misma operación, y no es un `UPDATE`.** `guardarManual()` da de baja la versión vigente e inserta una nueva, en una transacción — exactamente lo que hace un `CAMBIO` de la importación. Por eso hay **un solo formulario** y un solo endpoint: dos insinuarían que existe un camino que modifica en el lugar, y en este módulo no lo hay.

**Se normaliza con la misma función que la importación.** `normalizarFila()` era privada y ahora es pública: aplica el largo del código en caracteres, la normalización de la forma de pago contra `FORMAS_PAGO` y el plazo en días. Si la pantalla normalizara por su cuenta, el mismo proveedor quedaría clasificado distinto según por dónde entró, y no habría ninguna pantalla donde notarlo.

**El código no se puede cambiar al editar.** Es la clave con la que la fila cruza contra Tango y contra las fechas de pago ya cargadas; cambiarlo sería dar de baja un proveedor y dar de alta otro, y eso son dos gestos.

**Una baja no borra**: marca `VIGENTE = 0` igual que una baja de la importación. La deuda de ese proveedor pasa a contarse como *sin rubro* y el historial sigue explicando cómo se clasificaba antes.

#### La planilla manda, pero pisar trabajo manual se avisa

Hay dos fuentes escribiendo la misma tabla, y este README ya había descartado eso una vez: *"dos maestros en paralelo terminan discrepando"*. La decisión es la misma de entonces — **la planilla es la fuente** — así que una edición manual es una versión más y la próxima importación la pisa.

Lo que se agrega es que **no la pise en silencio**. La columna `ORIGEN` (`IMPORT` / `MANUAL`) hace posibles tres cosas:

| | |
| --- | --- |
| En la grilla del maestro | una columna que dice si esa versión salió de la planilla o de la pantalla |
| En el historial del proveedor | lo mismo, versión por versión |
| **En la previsualización del diff** | un aviso — *"N de los cambios pisan proveedores editados a mano"* — y la marca en cada fila |

> Entre trescientos cambios, los que borran lo que alguien cargó a mano son los únicos que esa persona querría revisar. El aviso dice cuántos son; la marca dice cuáles.

Se aplican igual al confirmar: el que decide es quien importa, viéndolo.

### Excluir una factura suelta

> Esto es **nuevo**. Antes sólo se podía excluir a un proveedor entero.

Una factura duplicada, una en disputa o una que se pagó por fuera de Tango no son un problema del proveedor: son un problema de **esa factura**.

**El motivo es obligatorio**, y lo valida `Proveedores::saveExclusionMasiva()` y no la pantalla —el endpoint es alcanzable sin pasar por la grilla—. Una factura sacada del cashflow sin motivo no la explica nadie tres meses después. Volver a incluirla borra el motivo: dejarlo haría que una factura incluida arrastre el texto de cuando estuvo afuera.

#### Se eligen varias y se confirman juntas, con UN motivo

> Esto **cambió**. Era un tilde por fila que actuaba solo y pedía el motivo con el `prompt` del navegador.

La columna es de **selección**, no de estado. Sacar plata del tablero no puede dispararse con un clic suelto, y el caso real no es una factura: son **las ocho de un proveedor**. Se resuelve con lo que la pantalla ya tenía — buscar el proveedor, *seleccionar todas las que se ven*, un motivo.

**Un motivo para todas, y no es una simplificación de la pantalla:** excluir las ocho facturas de un proveedor es **una** decisión, y ocho motivos distintos para una decisión son ocho oportunidades de que digan cosas distintas.

**Es una sola transacción**, igual que el tildado masivo de Echeqs y por el mismo motivo: ocho llamadas dejan la puerta abierta a que la quinta falle y el tablero quede a mitad de camino sin que nadie se entere. Las claves se normalizan **antes** de abrirla: un comprobante mal identificado en la fila once no puede descubrirse con diez ya escritas.

`saveExclusion()` de a una **no duplica nada**: delega en la masiva con una lista de uno. Dos caminos que tienen que hacer lo mismo divergen, y lo que no puede estar escrito dos veces es la transacción.

En la barra de selección, antes de apretar nada: **cuántas facturas y por cuánta plata**. El importe es el dato que hace que alguien note que seleccionó de más.

#### El motivo se pide en un diálogo del módulo

`Notificacion.pedirTexto()` — el mismo control que ya hacía `confirmar()`, con un campo adentro. `window.prompt` no se puede formatear, no entra un detalle largo, no valida nada y se ve como un error del navegador en vez de como una decisión del sistema. Y acá hay que **leer cuántas facturas y por cuánto antes de escribir el motivo**, que en un prompt no entra.

- **Devuelve `null` al cancelar y el texto al confirmar.** `confirmar()` sigue devolviendo un booleano: su respuesta es sí o no, y la de ésta es el texto. Un `false` que a veces es `''` obligaría a cada llamador a distinguir dos ausencias distintas.
- **Un campo obligatorio vacío no cierra el diálogo**: dice por qué en el mismo lugar donde se escribe, en vez de cerrar y fallar después contra el servidor.
- El armazón está escrito **una vez** (`abrirDialogo()`): lo delicado no es el HTML, es que cerrar con la cruz, con Escape o clickeando afuera **también sea una respuesta, y sea la negativa**. Dos copias de eso se desincronizan en la primera corrección.
- Sin Bootstrap se cae al `prompt` del navegador, igual que `confirmar()` se cae al `confirm`: es feo, pero preguntar es lo que no puede faltar.

#### Por qué no alcanzaba con mandarla a `PAGOS_EXCLUIDOS`

Ésta es la parte que no es obvia. **La fila del tablero usa `PAGOS`, y `PAGOS` pertenece al corte del cronograma, que no excluye nada.** Mandar la factura tildada al corte de excluidos la habría sacado de `PAGOS_CRONO_OPERATIVOS`, que hoy **no usa ninguna fila**: el tilde no habría movido un peso.

Verificado contra la base: la fila está configurada con `ORIGEN_SERIE = 'PAGOS'`, y ahí adentro hay **$72.500.993,87 en 8 vencimientos de `OGRAZ`** —rubro *Excluidos*— que entran igual porque cobra por echeq.

Por eso la exclusión manual sale también del primer corte, con serie propia, y ese corte pasa a tener **tres partes**:

```
PAGOS + PAGOS_FUERA_CRONOGRAMA + PAGOS_EXCLUIDOS_FACTURA = PAGOS_TODO
```

- **No cae en `PAGOS_FUERA_CRONOGRAMA`.** Ahí el aviso desglosa por forma de pago —*"esto no se planifica porque es un débito automático"*— y una factura excluida a mano lo ensuciaría.
- **Sigue en `PAGOS_TODO` y en `PAGOS_EXCLUIDOS`.** El importe no desaparece: queda auditable, y el proveedor **avisa cuánto es y con qué motivos** en cada carga del tablero.
- **Un proveedor excluido por rubro no se mueve.** Sacarlo de `PAGOS` sigue siendo apuntar la fila a `PAGOS_CRONO_OPERATIVOS` desde Parámetros; este cambio no toma esa decisión por nadie.

#### No se ven por defecto, y el cartel dice cuántas son

Ya se decidió que no van al cashflow, así que en el trabajo normal —revisar qué hay que pagar— son ruido. El interruptor **Ver excluidas** viene **apagado**, al revés que el de al lado.

Pero esconder plata sin decir cuánta es exactamente lo que este módulo no hace, y acá pesa más que en el otro filtro: **esas filas están escondidas por defecto**, así que sin el cartel del período no hay ninguna pantalla donde alguien note que existen. Una exclusión puesta en marzo que nadie recuerda es justo lo que el cartel evita.

> *Hay 1 factura(s) excluida(s) a mano por $ 7.110.342,48, escondidas y fuera del cashflow — tildá Ver excluidas para revisarlas.*

Los indicadores siguen midiendo lo visible y diciendo el universo al lado, como con los otros dos filtros. Cuando se muestran, la fila se atenúa y el pendiente va tachado: es la fila la que cambió de significado, no una celda.

### El rubro "Excluidos"

Son los socios y los movimientos que no son deuda comercial. **No se filtran en la consulta: se clasifican**, y la fila del tablero que los agrupa se inhabilita desde Parámetros. Así sacarlos es un bit y no un cambio de código, y siguen visibles en la pestaña de detalle, que es donde alguien puede notar que uno está mal clasificado.

### Hay una segunda lista de exclusión, y no manda

`RO_V_PROVEEDORES_EGRE_DIRECTORES` tiene 8 proveedores marcados como egreso de directores.

**Manda el maestro**, por dos motivos: es el que administración mantiene todos los días, y tiene 225 proveedores contra 8. La vista queda como **control**: si alguien figura en ella y no está marcado `Excluidos` en el maestro, se avisa.

> Una lista manda y la otra audita. Dos listas que **deciden** se contradicen y nadie se entera. Hoy la diferencia no es teórica: **2 de esos 8 tienen deuda pendiente** ($4,2 M).

---

## Las dos importaciones

Mismo circuito que Cob. Electrónicos: **plantilla CSV, previsualización del diff, y nada se escribe hasta confirmar**. El mecanismo está en `Class/Planilla.php`, escrito una sola vez para los tres importadores del módulo.

### Es CSV, no .xlsx, y se dice en pantalla

Leer un `.xlsx` necesita la extensión `zip` de PHP, que en este servidor **está instalada pero no habilitada**. Habilitarla es tocar el `php.ini` de producción y reiniciar Apache; el módulo no puede depender de que ese cambio esté hecho en cada entorno.

Si igual se sube un `.xlsx`, el parser lo **detecta por su firma** (`PK`) y dice qué hacer, en lugar de fallar con un archivo lleno de bytes binarios. Lo mismo con un `.xls` antiguo.

### La planilla viene sucia, y eso se muestra — no se arregla

> Un importador que corrige solo deja la planilla rota para siempre, porque nadie se entera nunca de que lo está. **Lo que hay que arreglar es la planilla.**

| Lo que trae la planilla real | Qué hace el importador |
| --- | --- |
| **Un código repetido** (1.222 únicos en 1.223 filas) | Deja en error **las dos** filas, nombrando cada una a la otra. Quedarse con la última elegiría por el usuario |
| **Códigos con `Ñ`, `&` o `+`** (27 proveedores) | Se cargan normal. Ver abajo: acá hubo un bug |
| `echeq` en minúscula | Matchea contra `ECHEQ`, y guarda el original al lado |
| `eqheck` (un typo real) | **No** matchea: se guarda tal como vino, con el normalizado en `null`, y se avisa |
| `50% ECOMMERC / 50% VENTAS` (2 casos) | Se detecta **sin inventar una lista de criterios válidos** — ver abajo |
| 84 filas sin rubro económico, 98 sin centro de costos | Se cargan igual, y el resumen dice cuántas son |

**Cómo se detecta el typo del criterio sin una lista declarada:** los criterios son texto que escribe administración, y declarar los válidos sería decidir por ellos. Lo que sí se puede afirmar sin inventar nada es que **un valor que aparece 2 veces y se parece 85% a otro que aparece 200 es sospechoso**. Eso se marca y se muestra; no se corrige.

Un criterio poco usado pero **distinto** de todos los demás no se marca: sin algo parecido y más frecuente no hay nada que afirmar, y avisar de todos los raros sería ruido.

### El código se mide en caracteres, no en bytes

Un bug que apareció en la primera importación real: `OGNUÑE` y `OGMAGÑ` se rechazaban como *"tiene 7 caracteres"* siendo proveedores válidos de Tango.

`strlen()` cuenta **bytes**, y en UTF-8 la `Ñ` ocupa dos. En `CPA01.COD_PROVEE` —`VARCHAR(6)` con collation `Latin1_General_BIN`— ocupa uno, así que el código entra perfectamente.

Son **27 proveedores** con caracteres no ASCII en el código: eñes, `&` y `+`.

Hay un segundo bug de la misma familia, más silencioso, que se corrigió junto con el primero: **`strtoupper()` tampoco es multibyte-safe.**

```
strtoupper('ognuñe')     →  'OGNUñE'    la eñe NO sube
mb_strtoupper('ognuñe')  →  'OGNUÑE'
```

Si la planilla trae el código en minúscula, `OGNUñE` **no matchea** contra el `OGNUÑE` de Tango y la fila queda sin cruzar sin que nadie entienda por qué. Toda normalización de código pasa ahora por `Planilla::codigo()`.

**Los acentos no se sacan**, a diferencia de `Planilla::normalizarTitulo()`: un título de columna se compara de forma laxa porque lo escribe quien arma el archivo, pero un código de proveedor es un identificador y `OGNUNE` y `OGNUÑE` pueden ser dos proveedores distintos.

Por el mismo motivo, las claves de cruce se declaran con la collation de Tango (`Latin1_General_BIN`) y no con la de la base (`Modern_Spanish_CI_AI`, que es **acento-insensible**): si no, para SQL Server `OGNUNE` y `OGNUÑE` serían el mismo valor en nuestras tablas y dos distintos en Tango. Hoy no hay ninguna colisión —se verificó—, pero el día que la haya, el UPDATE de baja del maestro daría de baja los dos y la fecha de pago de uno se le aplicaría al otro, sin error y en silencio.

En una base ya creada eso lo corrige `sql/cashflow_prov_locales_collation.sql`. **Conviene correrlo antes de la primera importación del maestro**, que es cuando las tablas están vacías.

### Las formas de pago son las de la planilla, no las que parecen razonables

La primera versión de `FORMAS_PAGO` se escribió a ojo: `CHEQUE`, `EFECTIVO`, `RETENCION`, `COMPENSACION`, `OTRO`. **Ninguno de esos cinco existe en el maestro real**, y los que sí existen faltaban. Resultado de la primera importación: **639 de 1.173 proveedores (54%) quedaron con la forma sin normalizar.**

Las seis reales, contadas sobre el maestro importado:

| | | | |
| --- | ---: | --- | ---: |
| CAJA | 390 | TARJETA CORP | 240 |
| TRANSFERENCIA | 259 | DEBITO | 32 |
| ECHEQ | 243 | MERCADO PAGO | 9 |

Agregar una forma es **agregar una entrada, y nada más**: las filas ya cargadas se renormalizan solas en el próximo pedido. Ver abajo.

### La forma normalizada se deriva al leer, no se congela al importar

`FORMA_PAGO` es un valor **derivado**: sale de pasar `FORMA_PAGO_ORIG` —lo que decía la celda— por `FORMAS_PAGO`, que vive en el código. Calcularlo al importar lo congelaba contra la lista de ese día, así que agregar una forma nueva no arreglaba ninguna de las filas ya cargadas.

**Y reimportar no es recalcular.** Hace el diff completo: necesita tener a mano el Excel vigente —si no es el mismo que se importó, aplica de paso cambios que nadie pidió—, propone bajas, y cada `CAMBIO` escribe una baja más un alta en el historial.

> Obligaba a correr una operación de **datos**, con efectos colaterales, para arreglar la consecuencia de un cambio de **código**. Eso es lo que estaba mal, no el trabajo de reimportar.

Se puede derivar al leer porque **el original está guardado en todas las filas**. Verificado sobre la base: 0 de 1.173 en el maestro y 0 de 15 en pagos tienen la normalizada sin su original. Y la normalización es una función pura de él.

Cuesta **7,23 ms por pedido** sobre las 1.173 filas del maestro, y el mapa se cachea, así que es una vez.

**La columna se sigue escribiendo igual**, y no es redundancia: guarda *qué decidió el sistema en esa importación*, que es lo que muestra el historial del proveedor. Lo que cambió es por dónde se **lee**.

Dos garantías, las dos con prueba:

- **Recalcular nunca borra un dato.** Sólo puede reconocer uno que antes no se reconocía. Una fila con la normalizada pero sin original —hoy no hay ninguna, pero una corrección a mano sobre la base podría dejarla así— conserva lo que tenga guardado.
- **El diff no se ensucia.** Compara contra `mapa()`, que es justamente lo que se renormaliza, así que reimportar la misma planilla sigue dando `SIN_CAMBIOS` y no 639 cambios falsos.

### Sólo el código es obligatorio

En la planilla real faltan datos en cientos de filas. Exigirlos haría que la importación **falle entera** por datos que administración todavía no cargó.

### Una baja masiva avisa distinto

Si el archivo daría de baja **más de la mitad** del maestro, lo más probable es que alguien exportó un filtro y no el maestro entero. El aviso lo dice, y **las bajas se confirman aparte, con su propio interruptor**: no pueden ser un efecto de importar.

### La planilla de pagos

Campos: **cód. proveedor, número de factura, fecha de pago, forma de pago**.

**El tipo de comprobante no es obligatorio**, a propósito: quien arma la planilla mira una factura y copia su número, no su tipo. Cuando falta se deduce de los pendientes del proveedor.

- Un comprobante **en cuotas** aparece varias veces en los pendientes y eso **no es ambigüedad**: es un comprobante con varios vencimientos. Se resuelve solo, y el importe que se reubica es la suma de sus cuotas —la fecha se carga por comprobante, no por cuota—.
- **Dos tipos distintos con el mismo número** sí lo son. Ahí la fila pide que se aclare con la columna `TIPO_COMP`, en lugar de elegir una.

**Lo que no cruza contra ningún pendiente es un error visible con su motivo**, y los tres casos se distinguen porque se arreglan distinto: número mal tipeado, proveedor equivocado, o factura que ya se pagó.

**El aviso que importa dice cuántos vencidos quedan resueltos.** Es la razón de ser de esta importación, y hay que verlo *antes* de confirmar.

---

## La clave incluye al proveedor, y es lo contrario de cobranzas

`RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL` tiene unicidad `(T_COMP, N_COMP)` y el cliente queda al lado. **Acá no se puede hacer eso:**

> En ventas el comprobante lo emitimos **nosotros**, así que tipo + número identifica un comprobante y punto. En compras lo emite el **proveedor**. Dos proveedores distintos emiten, cada uno, su factura `A-0001-00000001`.

Verificado: hay **10.293 pares `(T_COMP, N_COMP)` repetidos entre proveedores locales**, sobre 35.777 filas de `CPA04`. Con la clave de cobranzas, la fecha de pago de una factura de Andreani se le aplicaría a una de Telecom.

El vencimiento **no** entra en la clave: un comprobante en cuotas tiene varios, pero la decisión *"esta factura se paga tal día"* se toma por factura. Si algún día hace falta pagar cuota por cuota, se agrega `FECHA_VTO` a la clave y el resto del circuito no cambia.

---

## La conciliación

**Tango es la verdad sobre el pago.** Lo que se carga en la pestaña es una **previsión**: cuándo se piensa pagar. Cuando el comprobante aparece cancelado, deja de proyectarse —sale del listado por sí solo, porque su `ESTADO` ya no es `PEN`—.

### La previsión no se borra al conciliar

Queda marcada `CONCILIADO` con la **fecha real al lado**, sin pisar la prevista.

> Es lo único que permite después contestar la pregunta por la que vale la pena guardar las dos: **¿le acertamos a la fecha?** Borrándola, esa pregunta se queda sin respuesta para siempre.

Por eso el resumen informa el **desvío promedio en días** entre lo previsto y lo real —recién con cinco casos, porque con menos no significa nada—. Ese número dice si la previsión está sirviendo o si siempre se paga más tarde de lo que se dice.

### Detecta los dos sentidos

| | |
| --- | --- |
| **CONCILIA** | estaba `PREVISTO` y el comprobante ya no está pendiente: se pagó |
| **REABRE** | estaba `CONCILIADO` y volvió a estar pendiente. Pasa cuando se anula una orden de pago. Sin esto, esa deuda quedaría marcada como pagada para siempre aunque el tablero la vuelva a mostrar |

**Un comprobante que no aparece en Tango no se toca**: pudo haberse anulado, depurado, o cargado con un código que después cambió. Marcarlo como pagado sería inventar un hecho.

**Consulta sólo lo que tiene previsión**, no las 96.403 canceladas locales: la pregunta es *"de lo que tengo previsto, qué ya se pagó"*. La fecha real sale del último comprobante que canceló (`MAX(CPA05.F_COMP_CAN)`) y **no** de `CPA04`, donde no hay fecha de pago sino de emisión y de contabilización.

Como las dos importaciones, **no escribe nada hasta confirmar**.

---

## Integración con el tablero

`ProveedoresProvider` sirve el código `PROV_LOCALES` con **siete series fijas** y **una por rubro**:

| Serie | Qué trae |
| --- | --- |
| `PAGOS_TODO` | El universo completo, todas las formas de pago |
| `PAGOS` | **Sólo echeq y transferencia** — es la que usa la fila del tablero |
| `PAGOS_FUERA_CRONOGRAMA` | Sólo lo que el criterio deja afuera |
| `PAGOS_OPERATIVOS` | Todo menos los rubros `Excluidos` |
| `PAGOS_EXCLUIDOS` | Sólo los excluidos |
| `PAGOS_CRONO_OPERATIVOS` | **Los dos criterios a la vez** |
| `PAGOS_SIN_RUBRO` | Sólo los que no están en el maestro |
| `PAGOS_EXCLUIDOS_FACTURA` | Sólo las facturas excluidas a mano, una por una |
| `RUBRO_*` | Una por cada rubro económico del maestro |

**`PAGOS` no trae todo**, y el nombre engaña: trae el cronograma. Ver la sección anterior.

Son **dos particiones del mismo universo**, y las dos tienen que cerrar contra `PAGOS_TODO`:

```
PAGOS + PAGOS_FUERA_CRONOGRAMA + PAGOS_EXCLUIDOS_FACTURA = PAGOS_TODO  (por cómo se paga)
PAGOS_OPERATIVOS + PAGOS_EXCLUIDOS                       = PAGOS_TODO  (por qué rubro es)
```

Eso lo fija `tests/test_proveedores.php` contra los datos reales: si una de las dos no cerrara, algún comprobante se estaría yendo a la serie equivocada.

### Las dos particiones son independientes, y por eso hace falta la séptima

Los dos criterios no se implican: un socio con rubro `Excluidos` que cobra por **transferencia** entra a `PAGOS`. No es teórico —hoy es **$109,6 M de un solo proveedor** dentro de la fila del tablero—, y hasta que existió `PAGOS_CRONO_OPERATIVOS` no había forma de aplicar los dos criterios a la vez: apuntar la fila a `PAGOS_OPERATIVOS` saca a los socios pero mete de vuelta los débitos automáticos, que es lo contrario de lo que la fila quiere decir.

**Sacar a los socios del tablero sin perder el criterio del cronograma es apuntar la fila a `PAGOS_CRONO_OPERATIVOS` desde Parámetros**: configuración, no código.

`PAGOS_CRONO_OPERATIVOS` **no parte nada**: es la intersección de una mitad de cada partición, así que se solapa con las dos. `PAGOS_SIN_RUBRO` tampoco: cruza las cuatro. El validador lo sabe —ver abajo—.

La regla de reparto vive en `ProveedoresProvider::seriesDeItem()`, estática y pura, y los cuatro cuadrantes se verifican sin base.

**El proveedor crea una serie por cada rubro del maestro, aunque hoy no tenga deuda.** Si no, una fila configurada contra un rubro sin pendientes se dibujaría como *"sin datos"* —con el ícono de que su módulo no devolvió nada— en lugar de mostrar un cero limpio, que es lo cierto.

### Las series por rubro son datos, no código

Cuáles existen depende de lo que administración cargue en el maestro. Escribirlas en `CashflowRegistry` obligaría a tocar código cada vez que aparece un rubro nuevo, que es exactamente lo que ese registro existe para evitar.

Por eso el registro ganó `'series_extra' => [clase, método]`: un punto de extensión acotado, resuelto **una sola vez por pedido** y que **no puede lanzar**. Si el maestro no existe todavía, el proveedor se queda con sus series fijas y el editor de estructura sigue abriendo —un registro que revienta deja sin pantalla a doce módulos que no tienen nada que ver—.

`serieExiste()` pasa ahora por `meta()` y no por la lista cruda: si no, el validador rechazaría una fila configurada contra un rubro del maestro.

**El total y cualquiera de sus aperturas no pueden estar activos a la vez** —sería contar dos veces lo mismo— y las de rubro se agregan solas a `componentes` para que el validador lo rechace.

### El validador mira las partes entre sí, no sólo contra el total

La regla original comparaba el **total** contra cada una de sus partes. Eso dejaba un agujero: `PAGOS` y `PAGOS_OPERATIVOS` son **dos partes**, ninguna es el total, y **se solapan en $1.297 M** —el 89% del universo contado dos veces, sin un solo aviso—.

Por eso el registro declara `particiones`: un mapa `total → corte → series`.

```
por cómo se paga      PAGOS · PAGOS_FUERA_CRONOGRAMA · PAGOS_EXCLUIDOS_FACTURA
por si está excluido  PAGOS_OPERATIVOS · PAGOS_EXCLUIDOS
por rubro             PAGOS_SIN_RUBRO · RUBRO_*
```

Dos series del **mismo** corte pueden convivir —son dos mitades, y es justamente cómo se mete al tablero lo que hoy queda fuera de la fila—. Dos de cortes **distintos**, no. `PAGOS_CRONO_OPERATIVOS` no figura en ninguno a propósito: es la intersección de una mitad de cada corte, así que se solapa con las cuatro.

El corte *por rubro* lo completa `resolverExtra()` con las series del maestro. Dos rubros distintos nunca comparten un comprobante —cada uno tiene uno solo—, así que todas juntas son un corte: **partir la fila en alquileres, impuestos y logística sigue siendo válido**, que es para lo que existen.

> **Sin `particiones` declaradas no cambia nada.** Un proveedor que no las declara toma todas sus partes como un único corte, que es como se comportaba antes: los cuatro canales de Ventas siguen pudiendo estar los cuatro activos.

### Es un egreso, y devuelve importes positivos

El signo lo pone el `TIPO` de la fila, no el dato. Es la regla de todos los proveedores de egresos; ver la nota de `sql/cashflow_estructura.sql`.

### La fila se renombró

De *Proveedores Locales* a **Cuentas a Pagar Locales**. Está en la sección *Costo de Mercadería*, pero de los $1.361 M pendientes sólo unos 80 son mercadería: el resto es aduana (367 M en **dos códigos distintos**, `OGADUN` y `OGADUA`), seguros, logística, alquileres, servicios, tarjetas y bancos. Dejarla llamándose *Proveedores Locales* dentro de *Costo de Mercadería* haría que la fila diga una cosa y muestre otra.

Es un cambio de dato: el nombre vive en `NOMBRE` y se edita desde Parámetros.

---

## El filtro por forma de pago

**La pestaña abre mostrando sólo lo que se paga por echeq o transferencia**, y la fila del tablero trae lo mismo.

El criterio: entra lo que se paga **decidiendo cuándo**. Una transferencia o un echeq se emiten el día que alguien elige; un débito automático se debita solo y la caja se paga en el mostrador, así que no se planifican de la misma manera.

**Lo que no se sabe, entra y se marca.** Una forma de pago en `null` —porque el proveedor no está en el maestro, o porque lo que trajo la planilla no se reconoció— no es lo mismo que una forma que quedó afuera del criterio: es un dato que falta. Esconder deuda por un dato que falta es la peor razón para esconderla, y además garantiza que nadie lo complete nunca, porque deja de verse. Esas filas se dibujan con la marca *sin forma*, así que no se confunden con un echeq confirmado.

### Una forma en naranja es un typo, y no hay segundo caso

Una forma que llega con el normalizado en `null` se dibuja en naranja con su original: es un typo de la planilla —`eqheck`— y se arregla allá.

**Hubo un segundo caso y ya no existe:** una forma perfectamente válida que había quedado sin normalizar porque el maestro se importó antes de que estuviera declarada en `FORMAS_PAGO`. Eran 133 vencimientos por $88.970.448,93 —129 de `TARJETA CORP` y 4 de `CAJA`— que entraban al filtro como si no se supiera cómo se pagan, y había que distinguirlos con un mensaje aparte porque se arreglaban reimportando y no corrigiendo nada. Derivar la normalización al leer eliminó la categoría entera.

> Esto estuvo invisible un tiempo por otro motivo: `categoria()` no devolvía el `FORMA_PAGO_ORIG` del maestro, así que esas 133 filas se dibujaban *"sin forma"* en gris y la marca naranja —que existe exactamente para este caso— no se ejecutaba nunca.

### Hay tres formas de pago por fila, sólo una decide, y en la grilla se ve una sola columna

Confundirlas fue un bug.

| | Qué es | ¿Decide? |
| --- | --- | --- |
| `FORMA_PAGO_CRONOGRAMA` | Con qué forma hay que tratar a **esta factura**. La pone una persona en la grilla | **Sí**, y le gana al maestro |
| `FORMA_PAGO_MAESTRO` | Cómo se le paga a **ese proveedor**, según el maestro | **Sí**, cuando no hay override |
| `FORMA_PAGO` | Por qué vía salió o va a salir **ese pago**. La trae la importación de la planilla | **No.** Sólo se muestra |

> El filtro mira una **regla**, no un **hecho**. Lo resuelve `Proveedores::formaDelCronograma()`, estática y pura.

```
CRONOGRAMA = esDelCronograma( override de la factura ?? forma del maestro )
```

**En pantalla es UNA sola columna, editable, y muestra la que decide.** `FORMA_PAGO_VIGENTE` viaja ya resuelta en cada fila —la regla se escribe una vez, en el backend, y la grilla muestra lo que decide en vez de una aproximación suya—.

- La opción vacía del desplegable **se nombra**: `CAJA · del maestro`. Así el caso normal muestra la forma real *y de dónde sale*, y volver a ella es lo que saca el override.
- Elegir cualquier otra guarda el override y la celda se marca en violeta.
- **Cuando el hecho difiere de lo que decide**, va un ícono al lado con el detalle, no una columna propia. Hubo dos columnas y se unificaron: obligaban a leer dos celdas para contestar una sola pregunta, y hoy **no hay ni un comprobante donde difieran**.

> Lo que se ve es lo que decide. Es la propiedad que importa en una columna que está al lado de los importes del cashflow: si mostrara una cosa y el tablero usara otra, no habría dónde notarlo.

#### Por qué el override va en su propia columna

> Esto es **nuevo**. Antes la única regla era la del maestro, y cambiarla movía toda la deuda de ese proveedor.

Una factura puntual puede pagarse distinto sin que eso cambie cómo se le paga al proveedor en general. Hasta ahora no había dónde decirlo: o se cambiaba el maestro —y se movía todo— o no se decía.

**Lo que no se podía hacer es que decidiera `FORMA_PAGO`**, que era la columna que ya estaba. Esa es un *hecho*, y **la escribe la importación de la planilla de pagos en todas sus filas**: si decidiera, subir la planilla pasaría a mover comprobantes dentro y fuera del cashflow sin que nadie lo haya pedido. Hoy no hay **ni un comprobante donde las dos difieran**, así que el daño no se vería hasta la primera planilla que traiga una vía distinta de la habitual.

Por eso `aplicarImportacion()` **no toca `FORMA_PAGO_CRONOGRAMA`**, y hay una prueba que lo fija.

- **No toca el maestro.** Las otras facturas del mismo proveedor siguen clasificándose igual.
- **Se valida contra `FORMAS_PAGO`**, al revés que `FORMA_PAGO`, que guarda lo que diga la planilla. Ésta *decide*: una forma que no está en la lista no decidiría nada y quedaría como un override que parece puesto y no hace nada.
- **Sacarlo es elegir "maestro:" en el desplegable.** No borra la fila: la fecha de pago y la observación siguen estando, porque son otra cosa.

**Que el hecho difiera de la regla no es un error: es información.** Significa que a ese proveedor se le pagó por una vía distinta de la habitual, y lo que eventualmente hay que corregir es el maestro.

#### `savePago()` no pisa lo que no le mandaron, y ahora es estructural

`guardarPago()` recibe **un mapa columna → valor con exactamente lo que hay que escribir**, y lo que no está en el mapa no entra ni al `UPDATE` ni al `INSERT`.

Antes eran dos booleanos —`$tocarForma`, `$tocarObs`—, y cada override nuevo obligaba a agregar un flag más y a que todos los llamadores lo pasaran bien. El bug original: un endpoint que recibe un campo y escribe cuatro no está guardando una edición, está reemplazando la fila —y borraba la forma y la observación que había dejado la importación—.

#### La tabla de pagos pasó a ser la tabla de overrides

`RO_T_CASHFLOW_PROV_LOCALES_PAGO` nació como *"las fechas de pago"* y hoy guarda tres decisiones sobre un comprobante: **la fecha, la forma con la que se lo trata y si se lo excluye**. Por eso `FECHA_PAGO` es **nullable**: con `NOT NULL` no había forma de guardar un override sin inventarle además una fecha, y una fecha inventada no es un dato que falte —es un dato falso que después alguien lee como una decisión—.

Una fila sin fecha cae sola al escalón siguiente de la jerarquía, el vencimiento de Tango, que es exactamente lo que pasaba cuando no había fila.

### Los filtros se pueden apagar, y dicen cuánto esconden

Son tres interruptores, y **sus defaults no son todos iguales porque no significan lo mismo**:

| Interruptor | Arranca | Por qué |
| --- | --- | --- |
| *Sólo echeq y transferencia* | **prendido** | Es el cronograma, que es el trabajo normal |
| *Sólo vencidos sin fecha* | apagado | Es un filtro de un clic para aislar lo que falta fechar |
| *Ver excluidas* | **apagado** | Ya se decidió que no van: mostrarlas en el trabajo normal es ruido |

Al lado del período, siempre a la vista:

> *Quedan afuera $141.423.489,13 en 392 vencimiento(s) (TARJETA CORP $88.129.222,37 · DEBITO $52.128.205,29 · CAJA $1.166.061,47) — destildá Sólo echeq y transferencia para verlos. · Hay 1 factura(s) excluida(s) a mano por $7.110.342,48, escondidas y fuera del cashflow — tildá Ver excluidas para revisarlas.*

Un filtro que esconde plata sin decir cuánta es un filtro que miente.

### El tablero también filtra, y eso deja plata afuera

La fila *Cuentas a Pagar Locales* usa la serie `PAGOS`, que **no trae todo**: trae el cronograma. Es una decisión de negocio, y su consecuencia es que **los débitos automáticos, la caja y la tarjeta corporativa no se proyectan en el cashflow aunque esa plata igual salga**.

Por eso el proveedor **avisa cuánto quedó afuera, desglosado por forma**, en cada carga del tablero. Si esa plata tiene que entrar por otra fila, esa fila todavía no existe; mientras tanto el aviso es lo único que impide que desaparezca en silencio.

Meterla es configuración, no código: `PAGOS` y `PAGOS_FUERA_CRONOGRAMA` son las dos mitades del universo y **pueden convivir** en dos filas distintas —el validador lo permite justamente porque no se pisan—. Lo que no puede es `PAGOS_TODO` junto a cualquiera de sus partes.

Lo que entra al filtro, abierto por qué entra:

| | Importe | Venc. |
| --- | ---: | ---: |
| `ECHEQ` | 176.159.353,50 | 95 |
| `TRANSFERENCIA` | 1.092.047.604,71 | 38 |
| sin forma — **el proveedor no está en el maestro** | 6.297.561,76 | 21 |
| *(afuera)* `DEBITO` | *51.804.546,29* | *257* |
| *(afuera)* `TARJETA CORP` | *88.250.698,73* | *129* |
| *(afuera)* `CAJA` | *719.750,20* | *4* |

> Los `TARJETA CORP` y `CAJA` entraban al filtro hasta que la normalización pasó a derivarse al leer: eran **$88.970.448,93 en 133 vencimientos** dentro de la fila del tablero, porque el maestro se había importado con la lista vieja de `FORMAS_PAGO`. Salieron solos, sin reimportar y sin tocar un dato.

Lo único que sigue entrando por *"no se sabe cómo se paga"* son **$6.297.561,76 en 21 vencimientos** de proveedores que no están en el maestro — que es exactamente el caso para el que la regla existe.

---

## La pantalla

Tres sub-solapas, que son tres momentos del mismo circuito:

| | |
| --- | --- |
| **Cuentas a Pagar** | El listado, con la fecha editable celda por celda |
| **Importar** | Las dos planillas, con previsualización del diff |
| **Maestro** | Qué es cada proveedor, qué proveedores faltan, y el alta/edición de a uno |

> **Se llama *Cuentas a Pagar* y no *Pagos Reales***, que era el nombre propuesto. Lo que se carga es una **previsión**; lo real lo dice Tango cuando el comprobante se cancela, y eso lo resuelve la conciliación. Un rótulo que dijera *"reales"* prometería un hecho donde hay un plan.

**El indicador que importa es el segundo:** *Vencido sin fecha*. Va en rojo mientras haya algo y se apaga en verde al llegar a cero — una tarjeta que se ve igual con 839 millones pendientes y con cero no sirve para saber si hay trabajo por hacer. Hay un filtro de un clic para aislar exactamente esas filas.

### Las tarjetas miden lo que la tabla muestra

Los tres filtros —el buscador y los dos interruptores— son del navegador, así que los cuatro indicadores se suman ahí, en el mismo lugar donde ya se sumaba el pie de TOTALES.

> Un número arriba de una tabla describe esa tabla.

Antes salían del backend calculados sobre **todos** los vencimientos: con el filtro por forma de pago prendido —que es el default— la tarjeta decía *549 vencimientos* arriba de una tabla que mostraba **294**, y ni el buscador ni el interruptor de vencidos la movían.

**Lo que el filtro esconde no se pierde:** cuando lo visible difiere del universo, el pie de cada tarjeta dice el total. Es la misma regla del cartel de al lado del período, aplicada a las tarjetas.

Con una excepción deliberada: **la tarjeta roja se apaga en verde por el universo, no por lo visible.** Apagarla porque el filtro escondió lo que falta fechar diría que no hay trabajo por hacer justo cuando lo hay.

Los **avisos** sí siguen contando el universo —son la contrapartida de lo que no se ve— y cada uno lo dice. Un número que no coincide con el de la pantalla y no explica a qué se refiere se lee como un error del sistema.

**La fecha es lo único editable.** La celda tiene la misma pinta que la fecha manual de Cobranzas FR —es el mismo gesto— pero **sin `min` en hoy**: acá se aceptan fechas pasadas, porque el listado no tiene techo de antigüedad y *"se pensó pagar y no se pagó"* es una decisión legítima.

Guardar recarga la pestaña entera: la fecha cambia en qué columna del eje cae el importe, los totales del pie y los cuatro indicadores.

Componentes estándar: tarjetas KPI, buscador, selector de eje temporal (`Js/eje-vistas.js`), columnas fijas, Actualizar, Exportar a Excel y avisos por `Js/notificaciones.js`.

---

## Los dos controles

Sin ellos el maestro se desactualiza y nadie se entera.

1. **Proveedores con deuda que no están en el maestro.** Aparecen proveedores nuevos en Tango, nadie los agrega a la planilla, y su deuda queda sin clasificar —o peor, se la lee como si estuviera clasificada—. Se muestran ordenados **por importe**: si la lista es larga, lo que importa es por cuál empezar.
2. **Egresos de directores que el maestro no marca como `Excluidos`.** Ver arriba: manda el maestro, esto audita.

---

## Pruebas

```bash
php tests/run.php proveedores
```

`tests/test_proveedores.php` fija sin base lo que decide **qué número sale y dónde cae**:

- **La jerarquía de la fecha** entera, incluida la parte que es fácil romper: que el vencimiento le gane al plazo, que una fecha cargada no se reubique aunque esté vencida, que no haya techo de antigüedad, y que `CONTADO` (cero días) **se use** en lugar de tratarse como "sin plazo".
- **Que el plazo no es un número**: `DEBITO` devuelve `null` y no `0`.
- **La suciedad de la planilla**: que el código repetido deje en error **las dos** filas, que `echeq` matchee y `eqheck` no, y que el typo del criterio se detecte sin lista declarada —y que un criterio raro pero distinto **no** se marque—.
- **Que las filas en error no ensucien las estadísticas de calidad**: una que falló por el código ni siquiera llegó a leer el rubro.
- **El diff de pagos**: que el tipo se deduzca, que un comprobante en cuotas no sea ambiguo, que dos tipos con el mismo número sí lo sean, y que lo que no cruza dé un error con motivo.
- **Que la clave incluya al proveedor**: dos proveedores con el mismo comprobante dan claves distintas.
- **El desvío** de la conciliación en los dos sentidos.

Con base, además: que **ningún pendiente sea negativo** —el error que tenía la consulta antes de la tabla de signos—, que no entre ningún proveedor del exterior, que el total sea exactamente operativos + excluidos, y que **el registro declare exactamente las series que el proveedor devuelve**.

*Suite completa: 2078 OK, 0 fallas (18 archivos).*

---

## Archivos

```
sql/cashflow_prov_locales.sql                  Las dos tablas + la fila del tablero
sql/cashflow_prov_locales_collation.sql        Alinea la collation con la de Tango
sql/cashflow_prov_locales_maestro_manual.sql   ORIGEN: habilita la carga a mano
sql/cashflow_prov_locales_forma_por_factura.sql  El override de forma por factura
sql/cashflow_prov_locales_excluir_factura.sql    El tilde de exclusion por factura
sql/_referencia_tango_pendientes.sql           La consulta de Tango, como referencia
cashflow/Class/Planilla.php                    El mecanismo de importacion CSV, compartido
cashflow/Class/Proveedores.php                 Cuentas a pagar, fechas de pago y conciliacion
cashflow/Class/ProveedoresCategorias.php       El maestro y el resolutor de categoria
cashflow/Class/Providers/ProveedoresProvider.php
cashflow/Controller/ProveedoresController.php
cashflow/Tabs/proveedores_locales.php
cashflow/Js/Proveedores-Proveedores_locales.js
cashflow/Css/Proveedores-Proveedores_locales.css
tests/test_proveedores.php
```

Modificados: `Class/CashflowRegistry.php` (`PROV_LOCALES` disponible + `series_extra`) · `Class/CobElectronicos.php` (delega en `Planilla`, sin cambiar su contrato) · `Class/Menu.php` (la pestaña pasa a `DATOS`) · `tests/test_providers.php` y `tests/test_menu.php` (los conteos).

---

## Pendientes conocidos

- **639 de los 1.173 proveedores del maestro tienen la forma de pago sin normalizar en la columna** —`CAJA`, `TARJETA CORP` y `MERCADO PAGO`, las tres que faltaban en la primera versión de `FORMAS_PAGO`—. **Ya no afecta a nada**: la normalización se deriva al leer, así que esas filas se clasifican bien igual. La columna se acomoda sola la próxima vez que se reimporte el maestro por cualquier otro motivo; no hace falta hacerlo por esto.
- **ARCA/Aduana está cargada con dos códigos** (`OGADUN` $235,4 M y `OGADUA` $131,8 M, mismo nombre). No se unifican en el resolutor: la clave es el código de Tango y arreglar el maestro no le toca a este módulo. Si los dos llevan el mismo rubro, el tablero los junta solo. El control de faltantes los muestra por separado, que es lo que va a revelar si la planilla trae uno solo.
- **Las series por rubro se resuelven contra el maestro en cada pedido.** Con 26 rubros y un cache por request alcanza; si algún día el maestro creciera mucho, el lugar para mirar es `CashflowRegistry::resolverExtra()`.
