# Módulo Exportaciones Tasky — facturas pendientes en dólares

Pestaña **Ingresos → Exportaciones Tasky** y fila *Exportaciones Tasky* del tablero de Cashflow.

Rama: `feature/exportaciones-tasky`

---

## La idea en una línea

**Tasky es la razón social del grupo en Uruguay: mismo grupo, otra empresa.** Se le factura en dólares, y esas facturas pendientes de Tango son cobranza a proyectar. La pestaña las lista con una fecha de cobro estimada y **las valúa todas al dólar de hoy**; el proveedor lleva ese mismo número al tablero.

```
GVA12 (central): T_COMP = 'FAC', COD_CLIENT = 'EXTASK', ESTADO = 'PEN'
        │
        ├─ IMPORTE_EX ───────────────── los dólares. Es el dato que vale.
        ├─ COTIZ, IMPORTE ───────────── cómo se facturó. Referencia de pantalla, nada más.
        │
        ├─ + exportaciones_tasky_dias_cobro (Parámetros → Cobranzas, default 30)
        ▼
Fecha de cobro estimada = FECHA_EMIS + plazo      (si ya pasó: HOY, y se avisa)
        │
        ├─ × dólar oficial BCRA de HOY (Cotizacion::delMes(año actual, mes actual))
        ▼
   Pestaña "Exportaciones Tasky" (una fila por factura, eje temporal)
        │
        ▼
   ExportacionesProvider (serie COBRANZA) → fila EXPORTACIONES del tablero
```

Sigue el molde de **Cobranzas May** (`README-cobranzas-may.md`): facturas pendientes de `GVA12` proyectadas a emisión + plazo. Las diferencias son tres, y las tres están escritas más abajo: la moneda, la valuación y qué se hace con lo vencido.

---

## Ejecución de los scripts

Contra `central`, en este orden:

| # | Script | Qué hace | Si no se corre |
| --- | --- | --- | --- |
| 1 | `sql/cashflow_exportaciones_tasky.sql` | Siembra `exportaciones_tasky_dias_cobro` (30) y, si no existe, la fila `EXPORTACIONES` del tablero | La pestaña proyecta con **30 días** igual; la fila del tablero no existe **salvo** que la base ya haya corrido `cashflow_estructura_disponibilidades.sql`, que la creaba desde antes |
| 2 | `sql/cashflow_estructura.sql` (modificado) | La semilla nace con la fila `EXPORTACIONES` en Ingresos, orden 70, y el subtotal corrido a 80 | Sólo afecta a una **instalación nueva** |
| 3 | `sql/cashflow_estructura_disponibilidades.sql` (modificado) | `EXPORTACIONES` pasa de `INSERT` a `MERGE`, porque la semilla ya la crea | Ídem: en una instalación nueva el `INSERT` viejo reventaría por `CODIGO` UNIQUE. En una base ya migrada no hace nada |

Todos son reejecutables. **No hay tabla nueva**: los datos salen de Tango.

> **En la base actual la fila ya existía** —la creó `cashflow_estructura_disponibilidades.sql` en `DISPONIBILIDADES` con el proveedor marcado como no disponible— y rendía cero con aviso. Lo que cambia es que el proveedor ahora existe y la llena. El script 1 no toca una fila que ya está: respeta cualquier edición del usuario.

---

## Origen de los datos

```sql
SELECT CAST(A.FECHA_EMIS AS DATE), A.COD_CLIENT, B.RAZON_SOCI, A.T_COMP, A.N_COMP,
       CAST(A.IMPORTE_EX AS FLOAT), CAST(A.COTIZ AS FLOAT), CAST(A.IMPORTE AS FLOAT),
       CAST(ISNULL(IMPU.IMPUTADO_USD, 0) AS FLOAT) AS IMPUTADO_USD
FROM GVA12 A
INNER JOIN GVA14 B ON A.COD_CLIENT = B.COD_CLIENT
OUTER APPLY (
    SELECT SUM(CASE GVA07.T_COMP_CAN
                 WHEN 'REC' THEN GVA07.IMP_CAN_UN
                 ELSE CASE GVA15.TIPO_COMP WHEN 'D' THEN -GVA07.IMP_CAN_UN
                                           ELSE           GVA07.IMP_CAN_UN END
               END) AS IMPUTADO_USD
    FROM GVA07
    LEFT JOIN GVA15 ON GVA15.IDENT_COMP = GVA07.T_COMP_CAN
    WHERE GVA07.T_COMP = A.T_COMP AND GVA07.N_COMP = A.N_COMP
) AS IMPU
WHERE A.T_COMP = 'FAC' AND A.COD_CLIENT IN ('EXTASK') AND A.ESTADO = 'PEN'
```

- **Los dólares pendientes son `IMPORTE_EX - IMPUTADO_USD`.** Ese saldo es el que se transporta, se valúa y llega al tablero. `IMPORTE_EX` (lo facturado) e `IMPUTADO_USD` quedan en la fila como `IMPORTE_USD_FACT` e `IMPUTADO_USD`.
- **`IMPUTADO_USD` sale de las imputaciones (`GVA07.IMP_CAN_UN`), no de `ESTADO`.** `ESTADO` mira los pesos: una factura cobrada completa en dólares a una cotización distinta de la de facturación queda `PEN` por la diferencia de cambio en pesos. Caso real: la `E0011700004149` se facturó a 1.500 y se cobró completa con el `REC 0000300001872` a 1.400. Tiene `ESTADO_UNI = CAN` y sigue con `ESTADO = PEN`. **Una factura sin dólares pendientes no sale** (saldo menor o igual a medio centavo) **si tiene alguna imputación**: no es cobranza a proyectar. La condición es `IMPUTADO_USD != 0 && saldo <= 0,005` (`proyectarExportaciones()`), así que una factura con `IMPORTE_EX` en cero y sin imputaciones sí aparece, con *Importe USD* en cero. Los signos son los de Cobranzas May: un `REC` cancela (no está en `GVA15`), un comprobante de tipo `D` suma deuda y las notas de crédito cancelan.
- **`COTIZ` e `IMPORTE` son la cotización y el importe en pesos al momento de facturar.** Van en la pantalla, atenuados, como referencia histórica. **No entran en ningún cálculo**: si se cambian, ninguna otra columna se mueve (está probado).
- `'EXTASK'` es el único cliente por ahora. Está en `Ingresos::CLIENTES_EXPORTACION` y no repetido dentro del SQL: si mañana aparece otra exportadora, se agrega ahí.

---

## Valuación: todas las facturas a dólar de hoy

**Todas las facturas se valúan a la misma cotización, la de hoy.** Es el cierre del mes en curso de `RO_V_DOLAR_OFICIAL_BCRA` —`Cotizacion::delMes(año actual, mes actual)`, punta **compradora**—, que para el mes en curso es la última cotización **cargada en el mes en curso**. Si el mes todavía no tiene ninguna (los primeros días), no se toma la del mes anterior: ver *Si no hay cotización*. **No se valúa cada factura al tipo de cambio del mes en que se va a cobrar.**

Es deliberado: la deuda está fija en dólares y el cobro es futuro. Proyectar una cotización para ese mes sería meter una hipótesis de devaluación en el tablero; valuar a hoy es **no suponerla**, que es el criterio conservador que se pidió.

### Por qué se aparta de `Class/Cotizacion.php`

`Cotizacion` documenta que *"cada mes se valúa a su propio tipo de cambio"*, y `OtrosIngresosProvider` lo aplica. Esa doctrina es para series **históricas**: cada mes ya ocurrió, tiene su cotización de cierre, y reexpresar todo a moneda de hoy sería otra cuenta. Acá no hay cotización del mes de cobro porque ese mes no llegó. Son dos situaciones distintas y las dos están resueltas bien; lo que no hay que hacer es "arreglar" ésta para que parezca la otra. La nota está en el encabezado de `ExportacionesProvider.php` y de `Ingresos::proyectarExportaciones()`.

### Si no hay cotización

La pantalla **avisa** y deja la columna *Importe pesos hoy* vacía —un guión, no un cero—. La grilla queda sin importes y el aviso dice cuántos dólares hay sin valuar. El tablero hace lo mismo: la fila va en cero con un aviso que nombra la vista y el monto. **No se asume ningún valor**: ni el del mes anterior, ni un parámetro, ni el `COTIZ` de facturación. Mostrar la plata sin decir a cuánto se valuó es peor que no mostrarla.

Los dólares se guardan y se transportan siempre; la conversión la hace el proveedor, como `ComexProvider`. El motor nunca ve dólares.

---

## Fecha de cobro

`GVA12` trae `FECHA_EMIS` pero no fecha de cobro, así que se estima con el mismo mecanismo que Mayoristas:

```
Fecha de cobro estimada = FECHA_EMIS + exportaciones_tasky_dias_cobro
```

El parámetro vive en `RO_T_CASHFLOW_PARAMETROS` (`INT`, default `30`, `MODULO = 'COBRANZAS'`, `GRUPO = 'GENERAL'`) y se edita en **Parámetros → Cobranzas**, en la tarjeta *Plazos de Cobro*, junto al plazo de mayoristas. La pestaña usa 30 si el parámetro no está sembrado, si lo guardado no es un número mayor que cero o si no hay conexión a `central` (`getDiasCobroExportaciones()`).

Son **días corridos**: la fecha estimada no se corre a un día hábil (igual que Mayoristas). Si cae un sábado, domingo o feriado, queda ahí; el calendario sólo pinta la columna en gris.

### Las vencidas no se descartan: van a hoy y se avisa

Una factura de exportación con la fecha estimada en el pasado es **una factura vencida sin cobrar**, que es información, no un error a esconder. Se ubica en el **primer día del eje** —hoy—, la fila se marca en ámbar con la fecha original a la vista, y arriba de la tabla un aviso dice cuántas son y por cuántos dólares.

**Este criterio dejó de ser propio de esta pestaña: ahora es el de las tres.** Cobranzas FR y Mayoristas descartaban lo vencido con un `continue`, y eso se unificó en `Ingresos::ubicarCobroVencido()`, que es la regla única. `estimarCobroExportacion()` la llama pasando `null` como techo de días hacia atrás, y **ése es el único apartamiento**: las otras dos dejan afuera lo anterior a `DIAS_COBRO_VENCIDO` (180 días), acá no hay nada que dejar afuera por antigüedad porque son pocas facturas de un solo cliente y todas se gestionan. Ver `README-cobranzas-fr.md`.

Las clases `.fila-vencida` y `.badge-vencida-exp` se mudaron de `Css/Ingresos-Exportaciones_tasky.css` a `Css/main.css`, porque desde ahora las usan las tres pestañas y tres copias del mismo ámbar se desincronizan a la primera vez que alguien retoca una.

En el tablero, el proveedor anota esa parte de la celda de hoy con el mecanismo `detalle` (el mismo que usa Cobranzas FR para la cobranza pactada a mano): el tooltip dice que ese importe está ahí por ser el primer día del eje y no porque se estime cobrarlo hoy. Es metadato sobre el mismo importe, no una serie aparte: no suma dos veces.

---

## Pantalla

Una fila por factura. No hay Resumen / Detalle Facturas como en Cobranzas May: es un solo cliente, así que una fila por factura ya es el resumen.

Columnas: `FECHA_EMIS`, `N_COMP`, `COD_CLIENT`, `RAZON_SOCI`, `Importe USD`, `Cotiz. facturación`, `Importe pesos facturación`, `Cotiz. hoy`, `Importe pesos hoy`, `Fecha cobro estimada`, y la grilla temporal con las tres vistas de `Js/eje-vistas.js`. **El importe que va a la grilla es el de hoy.**

- Columnas fijas con `Js/columnas-fijas.js`: `N_COMP` y `RAZON_SOCI` por defecto. El rótulo *TOTALES* del pie va en la celda de `N_COMP`, que es la primera fija, así queda a la vista al scrollear. Qué celda del pie lleva cada total se resuelve por el **nombre** de la columna del encabezado, no por índice.
- **Abre ordenada por *Fecha cobro estimada* ascendente**: lo que primero entra, arriba. Es `porDefecto` en `crearOrdenTabla()`; un orden elegido a mano sigue mandando y el default nunca se escribe en `localStorage`. El `<th>` lleva `data-orden-nombre` **igual a su rótulo**, para que quien ya ordenó por esa columna no pierda su orden. La celda lleva `data-orden` con la fecha cruda: el badge de una vencida dice *"Vencida dd/mm/aaaa"* —se ordena por hoy, donde se dibuja— y una factura sin fecha de emisión, sin cobro estimado, **va al final** en las dos direcciones. Ver *Las que abren ordenadas* en `README-cashflow.md`.
- Pie de totales: total en dólares, total en pesos de hoy y los totales del eje.
- **El buscador** esconde las filas que no tienen el texto en ninguna celda (también fechas e importes), pero el pie y los totales de arriba suman sólo las filas cuyo `N_COMP`, `COD_CLIENT` o `RAZON_SOCI` lo tienen (`filasFiltradas()`). Buscando una fecha o un importe, se ven filas que el pie no suma. Y al cambiar de vista o tocar *Actualizar*, `generarTabla()` vuelve a dibujar todas las filas sin reaplicar el filtro, mientras el pie sigue filtrando.
- Totales arriba de cada fecha: en la fila del encabezado donde estaba la leyenda *Días* / *Meses*, el total de cada columna del eje y, arriba de *Total*, el total general. Son la misma cuenta del pie (`totalesEje()` sobre `filasFiltradas()`) con el mismo formato; los pinta `Js/eje-totales.js`. Los totales en dólares y en pesos de hoy siguen sólo en el pie: no son columnas del eje.
- Indicadores: *Pendiente en USD* y *Dólar de hoy* no varían con la vista —son un hecho de hoy—; *Vista Días*, *Vista Meses* y *Período Completo* miden exactamente las columnas de cada vista, como en todo el módulo.
- Avisos, arriba de la tabla y en este orden: falta de cotización, facturas vencidas, y lo que quedó fuera del horizonte o sin fecha. Los dos primeros los genera `Ingresos::avisosExportaciones()` y van **adelante** de los del eje a propósito: sin cotización la grilla queda vacía sin que `EjeVista` tenga nada que descartar, y una grilla vacía sin aviso no diría por qué.

---

## Tablero

`CashflowRegistry['EXPORTACIONES']` ahora tiene `'disponible' => true`, apunta a `Providers/ExportacionesProvider.php` y expone la serie `COBRANZA` ("Cobranza de exportaciones Tasky"). La moneda sigue siendo `USD`.

**El total de Disponibilidades cambia exactamente en el total de la pestaña, ni más ni menos.** Verificado contra la base al construirlo: `Total Disponibilidades = suma de las otras filas + total_horizonte de la pestaña`, y `tests/test_exportaciones_tasky.php` lo prueba tanto con datos conocidos como contra la base cuando hay conexión.

---

## Pruebas

```bash
php cashflow/tests/run.php exportaciones
```

`tests/test_exportaciones_tasky.php` cubre, sin base: la fecha estimada (emisión + plazo, plazo cero, sin fecha de emisión); la factura vencida que cae antes del eje y se ubica en hoy conservando la fecha original; la conversión a pesos con cotización y **sin** cotización (`null`, no cero); que `COTIZ` e `IMPORTE` de `GVA12` no mueven nada; el reparto de cada factura a la columna que le corresponde y la reconciliación tramo + meses = horizonte; los avisos; el agregado por fecha; y el proveedor con un `Ingresos` falso —conversión, anotación de las vencidas, fila en cero con aviso sin cotización, y que no puede tumbar el tablero—. Con base, además verifica la consulta real y que el tablero sume lo que muestra la pestaña.

El proveedor tiene una costura para las pruebas: `ExportacionesProvider::ingresos()` devuelve el `Ingresos` del que saca los datos, y las pruebas lo reemplazan por uno que devuelve filas conocidas. Misma idea que el `Horizonte` inyectado del motor.

---

## Archivos

```
sql/cashflow_exportaciones_tasky.sql              Parámetro + fila del tablero (migración)
sql/cashflow_estructura.sql                       Semilla: fila EXPORTACIONES en Ingresos (modificado)
sql/cashflow_estructura_disponibilidades.sql      EXPORTACIONES pasa a MERGE (modificado)
cashflow/Class/Ingresos.php                       CLIENTES_EXPORTACION, getExportacionesTasky(),
                                                  getExportacionesTaskyTotales(), getCotizacionHoy(),
                                                  proyectarExportaciones() y compañía (modificado)
cashflow/Class/Providers/ExportacionesProvider.php
cashflow/Class/CashflowRegistry.php               EXPORTACIONES disponible (modificado)
cashflow/Class/Menu.php                           exportaciones_tasky pasa a 'datos' (modificado)
cashflow/Controller/IngresosController.php        getExportacionesTasky (modificado)
cashflow/Tabs/exportaciones_tasky.php             La pantalla (reemplaza al placeholder)
cashflow/Js/Ingresos-Exportaciones_tasky.js
cashflow/Css/Ingresos-Exportaciones_tasky.css
cashflow/Tabs/parametros_cobranzas.php            Tarjeta Plazos de Cobro (modificado)
cashflow/Js/Parametros-Cobranzas.js               Carga y guarda los dos plazos (modificado)
cashflow/Js/Parametros.js                         Formato y hint del parámetro (modificado)
tests/test_exportaciones_tasky.php
```

---

## Qué no hacer

- **No usar `COTIZ` ni `IMPORTE` de `GVA12` para calcular nada.** Son referencia histórica de pantalla.
- **No valuar por mes de cobro.** Es a dólar de hoy, a propósito. Ver arriba.
- **No hardcodear la fila del tablero en PHP.** Es una fila de `RO_T_CASHFLOW_CONF_FILA` como cualquier otra.
- **No descartar importes en silencio.** Las vencidas van a hoy con aviso; sin cotización se avisa; lo que cae fuera del eje lo informa `EjeVista`.
