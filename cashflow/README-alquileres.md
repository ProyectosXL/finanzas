# Módulo Alquileres — cuánto se paga de alquiler por local, y cuándo

Pestaña **RRHH y Operativos → Alquileres** (sub-pestañas *Proyección*, *Ventas* y *Facturas*), la sub-pestaña **Parámetros › Alquileres** y el proveedor `ALQUILERES` del tablero, en una fila agrupada en **real** y **proyectado**.

Ramas: `feature/alquileres-proyeccion` (la proyección, partes 1 a 11), `feature/alquileres-facturas-exclusion` (las facturas reales, la exclusión por módulo y el tablero, partes 12 a 15) y `feature/alquileres-imputacion-razon-social` (la imputación por razón social, la exclusión de facturas, qué factura cada razón social de un local, la grilla por razón social, los fijos de un período abierto y el origen de cada importe).

---

## La idea en una línea

**El alquiler de cada local se proyecta desde la app de alquileres de administración: los meses cerrados se toman tal cual, los fijos cargados en un período abierto también, los demás fijos se ajustan por inflación según la frecuencia del local, las expensas crecen con su variación histórica y los variables se calculan con la venta del local y las fórmulas de la app. A cada importe se le suman el IVA y la percepción del local, se le resta la retención y se lo ubica el día de vencimiento de la razón social que lo factura, en el mes que corresponde. Una factura imputada pisa todo lo de su razón social en sus locales ese mes.**

```
App de alquileres (central, SÓLO SE LEE)        Parámetros › Alquileres (cashflow)
  RO_V_SUCURSALES_ALQUILERES  (los locales)       día de vencimiento general
  RO_T_DETALLE_ALQUILERES     (18 conceptos)      razones sociales DADAS DE ALTA
  RO_T_DETALLE_ALQUILERES_ESTADO (cerrado)          (día propio, meses de diferimiento)
  RO_T_PORC_CONCEPTOS_ALQUILERES (% por local)    por local: razón social, frecuencia,
  RO_T_RENTABILIDAD_BRUTA  (venta neta)             mes del último ajuste, % IVA,
  RO_V_VENTAS_BRUTAS_IE    (venta bruta)            % percepción, % retención,
        │                                           fecha de fin, venta manual
        │                                         pagos extraordinarios
        │                     Ventas (canal LOCALES, mes completo)
        │                     Generales (inflación, IVA)            │
        ▼                                ▼                          ▼
  AlquileresVenta  ─── venta de cada local: Real · Estimada · Manual
        │
  AlquileresCalculo ── neto de cada concepto y mes: Real (cerrado) · Cargado en app (abierto) ·
        │               Venta real · Estimado
        │
  AlquileresPago ───── neto + IVA + percepción − retención, mes y día de pago
        │                         (LA regla: una sola)
        │
  AlquileresImputacion ── una factura imputada pisa su razón social en sus   ◄── Tango (getPendientes)
        │                  locales ese mes (LA regla: una sola)                   + imputaciones
        ├──► pestaña Alquileres (agrupa por mes)
        └──► AlquileresProvider: REAL (facturas) + PROYECTADO (acumula por día) ──► tablero
```

**El cashflow no escribe en la app de alquileres.** Sólo la lee, y lo fija una prueba.

**Sólo Argentina.** El entorno Uruguay de la app (`$_SESSION['entorno'] = 'uy'`) queda afuera.

---

## Ejecución de los scripts

| # | Script | Base | Qué hace | Si no se corre |
| --- | --- | --- | --- | --- |
| 51 | `sql/cashflow_alquileres.sql` | `central` | Crea las cinco tablas de Parámetros › Alquileres (generales, razones sociales, locales, extraordinarios y sus locales) con auditoría e historial. Da de alta **SAPALA, SAARCO, SAPAMA y SAUNIC** con 2 meses de diferimiento, sólo si tienen `RUBRO = ALQUILER` en el maestro y no existen | La pestaña y Parámetros **cargan** y avisan, nombrando el script; ningún local proyecta. El resto del cashflow funciona como hoy |
| 52 | `sql/cashflow_alquileres_permisos.sql` | `apps` | Crea, si no existen, `cashflow.tab.alquileres`, `cashflow.editar.alquileres` y `cashflow.editar.parametros.alquileres`, colgadas del mismo módulo que el resto de `cashflow.tab.%`. **No asigna ningún rol** | Sólo el administrador ve la pestaña y edita Parámetros › Alquileres |
| 53 | `sql/cashflow_alquileres_facturas.sql` | `central` | El vencimiento a considerar por cuota (`RO_T_CASHFLOW_ALQ_FACTURA_VTO`), con auditoría e historial. Hasta el 20/11/2026 creaba también la imputación por renglones; esas tablas las borra el 60 y el 53 ya no las crea | Las facturas de alquiler **siguen en Cuentas a Pagar Locales** (condición de seguridad, parte 12) y las pantallas lo avisan en crítico; no se cambia un vencimiento |
| 54 | `sql/cashflow_prov_exclusion_modulos_ampliar.sql` | `central` | Amplía el `CHECK` de la exclusión por módulo a `TARJETAS_CORP` y `ALQUILERES` | Excluir de Tarjetas o de Alquileres falla con un mensaje que nombra el script |
| 55 | `sql/cashflow_alquileres_fila.sql` | `central` | Reemplaza la fila *Alquileres* (`ALQUILERES/PAGOS`) por dos filas agrupadas, `REAL` y `PROYECTADO`, en una transacción, con el control de doble conteo al pie | La fila de antes sigue activa y muestra el total, sin abrir |
| 58 | `sql/cashflow_alquileres_local_razones.sql` | `central` | Las razones sociales **adicionales** de un local (parte 3.c), con historial. No migra nada | Una razón por local, como antes; el *+ otra razón social* queda deshabilitado y nombra el script |
| 59 | `sql/cashflow_alquileres_local_vigencia.sql` | `central` | Agrega a `RO_T_CASHFLOW_ALQ_LOCAL` **`MODO_PROYECCION`** (`APERTURA` / `VIGENCIA`, NULL = apertura), con su `CHECK` (parte 3.c), y `FECHA_INICIO`, que quedó sin uso. No migra nada | Todos proyectan según apertura, como hoy; *Proyecta según* queda deshabilitado y nombra el script |
| 60 | `sql/cashflow_alquileres_imputacion_razon.sql` | `central` | Los **tipos de retención** (parte 3.e), sembrados sin pisar. En una transacción, **borra físicamente** las imputaciones del modelo de renglones y sus cinco tablas —imprime antes cuántas filas tenía cada una— y crea las de la imputación por razón social (parte 13): cabecera, cuotas, locales y retenciones. **Es la única baja física del módulo**, autorizada para este cambio | Facturas carga, **avisa en crítico** nombrando el script y no deja imputar; la proyección no se reemplaza con nada y las facturas no suman hasta que se imputen |
| 61 | `sql/cashflow_alquileres_excluir_factura.sql` | `central` | Las **facturas excluidas** (parte 14.b): una vigente por cuota, motivo obligatorio, volver a incluir es baja lógica | No se excluye nada: *Excluir* no se dibuja |
| 62 | `sql/cashflow_alquileres_local_razon_conceptos.sql` | `central` | **Qué conceptos factura cada razón social adicional** de un local (parte 3.c), con historial | Todo va a la principal, como antes; el selector de conceptos queda deshabilitado y nombra el script |

El 51 va después de `sql/cashflow_prov_locales.sql` (el maestro de proveedores): sin el maestro crea las tablas igual pero no siembra a nadie, y lo dice. El 52 es independiente. **53 → 54 → 55**; el **58**, en cualquier momento después del 51. El 53 después del 51, el 54 después de `sql/cashflow_prov_exclusion_modulo.sql`, el 55 después del 53 y de `sql/cashflow_estructura_grupos.sql`. **El 60 y el 61 después del 53; el 62 después del 58.**

**El tablero se mueve con el código, no con los scripts**: con el módulo disponible, la fila de antes (activa, `ALQUILERES/PAGOS`) muestra el total. Ver parte 15.

### Después de correrlos

1. Dar `cashflow.tab.alquileres` y, a quien corresponda, `cashflow.editar.parametros.alquileres` (Parámetros › Alquileres) y `cashflow.editar.alquileres` (Facturas: vencimiento e imputación, y la columna *ALQUILERES* del Maestro de Proveedores Locales) desde Gestión de Usuarios.
2. En *Parámetros › Alquileres*: cargar el **día de vencimiento general**, dar de alta las demás **razones sociales**, completar los **locales** (el filtro *Incompletos* muestra los que faltan), **marcar los conceptos de cada razón social adicional** (San Justo: a SAUCCS el 6, 7, 16, 17 y 11), revisar los **tipos de retención** y cargar los **pagos extraordinarios**.
3. **Después del 60, reimputar** con la pantalla nueva las facturas de las imputaciones que se borraron. Al 20/11/2026 eran dos, las dos ya pagadas en Tango (se ven como *Pagada, sin imputar*): **SACORR FAC A0000200000467** ($ 1.675.801,60, retención $ 102.528) y **SAARCO FAC A0001300017366** ($ 32.489.027,79, retención $ 1.542.297,45). Mientras no se reimputen, **noviembre sube $ 42.294.950,15**: vuelve la proyección de Gurruchaga (1) y Distrito Arcos (8, 9 y 11).

---

## 1. Los conceptos, en cuatro grupos

La lista es **una sola constante**, `AlquileresConceptos::GRUPO`, por número de concepto de la app (`ID_CA`) y no por nombre: el nombre lo edita administración, y la app misma se rompe cuando cambia (busca el 7 por *"Porc. S/ventas netas"*).

| Grupo | Conceptos | Cómo se proyecta |
| --- | --- | --- |
| **Fijos** | 1 Alquiler, 2 Complementario, 3 Baulera, 8 Valor mínimo mensual (VMA) | El importe del **último período cerrado** del local, ajustado por inflación según su frecuencia (parte 4) |
| **Fondo de promoción** | 9 | **VMA proyectado × %9** de la tabla de porcentajes, que es lo que calcula la app. Sin %9, mantiene la proporción sobre el VMA del último cerrado; si ese VMA es 0, se actualiza como un fijo |
| **Expensas** | 11 | La mediana de las variaciones mes a mes de los últimos 24 meses cerrados (parte 5) |
| **Variables** | 6, 7, 14, 16, 17 | Con la venta del local (parte 6) y los porcentajes de la app, con las fórmulas de la app (parte 7) |

- **No se proyectan** 10, 12, 13 y 15. Tampoco se toman de un mes cerrado: la pestaña muestra siempre los mismos conceptos.
- **No se proyectan en Alquileres** 4 (renovación de contrato / llave), 5 (comisiones) y 18 (FPC lanzamiento): los proyecta **Llaves y Renovaciones** con los importes del contrato de la app (`RO_T_CONTRATOS_ALQUILERES`) o, sin renovación cargada, estimando la llave con el 1 + 2 + 8 que proyecta esta pestaña (`Alquileres::alquilerMensual()`). El 4 que la app guarda por mes en el detalle es el devengado de Costo de Ocupación, no un pago, y tampoco se lee. Ver `README-llaves-renovaciones.md`.

### El concepto 9 no es un importe fijo

La ayuda de la app (`components/modalAyuda.php`) dice que el 9 es *"un importe fijo configurado previamente"*. **El código dice otra cosa y manda el código**: `totalizar()` en `js/cargaAlquileres.js` lo recalcula como `VMA × %9 / 100` cada vez que se abre el período. En los períodos cerrados de agosto y septiembre de 2026 coincide al peso en los 17 locales que tienen %9.

---

## 2. La app de alquileres: qué se lee y qué no

`Class/AlquileresApp.php`. Cada método lee una tabla y devuelve mapas por local y mes; los cruces se hacen en PHP.

### Un período abierto se lee sólo para los fijos

Con el período **cerrado** —tiene fila en `RO_T_DETALLE_ALQUILERES_ESTADO`— lo guardado es el valor final y se toma tal cual (`detalleCerrados()`). Con el período **abierto**, sólo sirven los **fijos** (1, 2, 3 y 8) cargados con importe mayor que 0 (`detalleAbiertos()`): son importes que administración carga de la factura o del contrato, y valen lo que se ve.

**Los variables de un período abierto no se leen**: la base guarda el 6 y el 7 **sin restar el VMA** (y el 16 y el 17 sin restar el 9); la resta la hace el JS de la app al mostrar (`totalizar()`), así que lo guardado no es lo que se ve. Verificado contra la base en 9-2026 y 8-2026: con el período cerrado, 6, 7, 16 y 17 coinciden con `max(0, venta × % − VMA o 9)`, con diferencias de $1 porque la app trunca a pesos enteros (`parseInt`). **El 9 tampoco**: la app lo recalcula como VMA × %9, y el cashflow hace la misma cuenta con el VMA de la app. Las expensas siguen con su tasa desde el último cerrado. Qué se hace con un fijo abierto está en la parte 4.

La app reabre un período **borrando** su fila de estado. El cashflow no guarda copia: un período que se cierra, se reabre y se vuelve a cerrar se lee como quedó.

### Los dos formatos de período

`PERIODO` es `'M-YYYY'` sin cero a la izquierda (`'9-2026'`) en el detalle, el estado y la venta bruta; `FECHA` de la venta neta es un `DATE`, el último día del mes. Todo se pasa a `'Y-m'` con `AlquileresApp::mesDePeriodo()` y **se compara exacto**, en PHP (ver *Pendientes conocidos*).

### El universo de locales

Los mismos que la app en su modo histórico: `RO_V_SUCURSALES_ALQUILERES` con `VISIBLE_FINAL = 1`, el filtro de `getSucursalesAlquileres()` sin período. Al 10/11/2026 son **26**: 21 habilitados y 5 con `HABILITADO = 0` en `SUCURSALES_LAKERS`. No hay una lista propia: Parámetros sólo completa datos.

---

## 3. Parámetros › Alquileres

`Class/AlquileresParametros.php` (lectura, escritura y validadores puros), `Tabs/parametros_alquileres.php`, `Js/Parametros-Alquileres.js`. Permiso propio: `cashflow.editar.parametros.alquileres`. La lectura es la de Parámetros.

**Una fila vigente por clave, con historial.** Cambiar un dato es dar de baja la fila vigente y dar de alta otra, en una transacción: cada cambio queda con quién y cuándo, y rige desde ese momento. No hay bajas físicas.

### 3.a Generales

El **día de vencimiento** por defecto (1 a 28: hasta el 28 para que exista en febrero). **No se siembra**: sin él, las razones sociales sin día propio no proyectan y se avisa.

### 3.b Razones sociales: se dan de alta, no salen del rubro

**El rubro no define el universo.** La sección arranca vacía y tiene un buscador con los proveedores que tienen **`RUBRO = ALQUILER`** —la columna `RUBRO` del maestro de Proveedores Locales, no el rubro económico—, con la comparación tolerante de `ProveedoresOpciones::buscarEnLista()`. De ahí se dan de alta los que son alquileres de locales: con ese rubro aparecen también proveedores que no lo son. **El alta se valida en el servidor** contra ese universo.

Al 10/11/2026 el rubro trae **23** proveedores: los 21 del rubro económico *Alquileres* más **Mercado Libre** (OGMERC, rubro económico *Arancel Comisión tarjetas de crédito*, $44,4 M pendientes) y **Sploder Industrial** (OGSPLO, *Alquiler Uruguay Prorrateo*, $555,1 M pendientes). Ninguno de los 21 del rubro económico queda afuera.

- Sólo las dadas de alta tienen **día de vencimiento propio** (vacío = el general) y **meses de diferimiento** de los conceptos variables (arranca en 0), y sólo ellas se eligen como razón social de un local o de un extraordinario.
- **Esto también decide qué factura es de alquiler** (`AlquileresFacturas`, parte 12): la de un proveedor dado de alta acá, no la de un rubro.
- **Dar de baja una razón social con imputaciones de facturas todavía pendientes se bloquea**: el mensaje lista cuáles deshacer (factura, mes que cubre, quién la cargó). Las de facturas ya pagadas no bloquean.
- Aviso de **atención**, acá y en el tablero, por los proveedores con `RUBRO = ALQUILER` **excluidos de Proveedores Locales y no dados de alta**: su deuda no está en ninguna fila. Lista código, razón social y pendiente, con el link al Maestro con *Sólo excluidos*. No mira el motivo de la exclusión, que es texto libre.
- Un proveedor dado de alta que **pierde el rubro** en el maestro **sigue de alta**, con un aviso de atención: darlo de baja es una decisión.
- **Dar de baja** una razón social que tiene locales los deja *Incompletos* ("razón social dada de baja"), con aviso.
- Aviso informativo: *"N proveedores con rubro ALQUILER no están dados de alta: sus facturas siguen en Proveedores Locales"*, con la lista.
- El script da de alta **IRSA (SAPALA), Arcos del Gourmet (SAARCO), Panamerican Mall (SAPAMA) y Cencosud (SAUNIC)** con 2 meses de diferimiento, por código. Cencosud tiene otro código, OGEASY, de mantenimiento, que no se toca.

### 3.c Locales

Por local de la app: **razón social**, **frecuencia** (mensual, bimestral, trimestral, semestral), **mes del último ajuste** (obligatorio salvo mensual), **% IVA** (arranca sugiriendo la alícuota de Generales y se puede subir o bajar: hay locales con otros cargos), **% percepción** y **% retención** (obligatorios; 0 es un valor, vacío no), **vencimiento del contrato** (opcional; la columna `FECHA_FIN`) y **venta mensual estimada** (neta; sólo para locales nuevos).

**Proyecta según** (script 59, `AlquileresPago::vigente()`), por local:

| Modo | Proyecta | El vencimiento del contrato |
| --- | --- | --- |
| **Apertura** (el de siempre; vacío = apertura) | Mientras el local esté abierto en Lakers | Sólo avisa: se asume la renovación |
| **Vigencia del contrato** | Sólo los meses cubiertos por **alguno de sus contratos de la app**, de su mes de inicio a su mes de vencimiento. Un variable de un mes vigente que se paga diferido después sí se paga: la venta existió. En la venta, reparte sólo en esos meses. Sin contratos en la app, *Incompleto* | Corta: cuando vencieron todos, el local queda *Contrato finalizado* (no es incompleto) y no se muestra en la pestaña salvo que le quede algo por pagar |

**LOS CONTRATOS SALEN DE LA APP DE ALQUILERES**, de `RO_T_CONTRATOS_ALQUILERES` (`VIG_DESDE` a `VIG_HASTA`, `AlquileresApp::contratos()`): no se cargan en Parámetros. Un local tiene varios —los viejos, el vigente, a veces uno corto en el medio— y cuentan todos. La columna *Contrato (app)* de Parámetros muestra el de hoy, el próximo o el último (`AlquileresPago::contratoActual()`) y el tooltip, todos. Las columnas `FECHA_FIN` y `FECHA_INICIO` de `RO_T_CASHFLOW_ALQ_LOCAL` ya no se usan: la fecha de fin cargada a mano era una copia de esa tabla.

Es para los **locales temporales**: XL RURAL proyecta según la vigencia. Al 17/11/2026 su único contrato en la app es del 03/02 al 03/03/2026, así que queda *Contrato finalizado*; el contrato de un mes nuevo se carga en la app y desde ahí proyecta ese mes.

Según apertura, **el vencimiento del contrato (el último de la app) no corta la proyección**: se asume que se renueva con las condiciones de hoy. Un contrato vencido da un aviso de atención (*si se renovó, cargá el contrato nuevo en la app*; al 17/11/2026, SOLAR, vencido el 30/09/2026) y uno que vence dentro del horizonte, uno informativo (`Alquileres::avisoContratos()`). Un local deja de proyectar sólo si **cerró en Lakers** (`HABILITADO = 0`). Hasta el 17/11/2026 la fecha cortaba todo desde el mes siguiente; con 8 locales con fecha dentro del horizonte (Distrito Arcos y MDP Gallegos al 31/12/2026, Alto Palermo, Abasto, Parque Brown, San Justo, Gurruchaga, Tortugas), el total del horizonte pasó de $ 3.311,4 M a $ 4.204,1 M.

- **Más de una razón social por local** (script 58). La del desplegable es la **principal**; debajo, *+ otra razón social* agrega **adicionales**: las que le facturan algo más. Hace falta la principal para agregar una; se quita con la ✕ (baja lógica, con historial). La columna *Locales* de Razones sociales cuenta las principales y, aparte (*+N*), las adicionales. `AlquileresPago::razonesDelLocal()` es la lista; `AlquileresParametros::validarRazonLocal()`, la validación.
- **Qué factura cada razón social** (script 62). **Por defecto todo va a la principal.** Al lado de cada adicional hay un selector de los conceptos que ella factura (de los que se proyectan: 1, 2, 3, 6, 7, 8, 9, 11, 14, 16 y 17), y esos **salen de la principal**; la principal muestra, de sólo lectura, *el resto*. **Un concepto es de una sola razón social por local**: el servidor lo valida (`AlquileresParametros::validarConceptosRazon()`). Cambiarlo es baja de la lista vigente y alta de otra, con historial. `AlquileresPago::conceptosPorRazon()` es la regla. De eso depende **con qué día y qué diferimiento se paga** cada concepto (parte 9), **bajo qué razón social se ve** (parte 10) y **qué pisa una factura** de esa razón (parte 13).
  - **San Justo (80)**: SASANJ (CONTINENTAL URBANA), la principal, factura el VMA (8) y el FPC s/VMA (9); SAUCCS (URBANA CENTROS COMERCIALES) el % s/ventas (6 y 7), el FPC s/ventas (16 y 17) y las expensas (11). Las dos tienen 1 mes de diferimiento y el día general, así que el reparto no mueve ninguna fecha. San Justo no tiene %14: si alguna vez lo tiene, como es un % sobre el 7, va con SAUCCS.
  - Una adicional **sin conceptos** sigue sirviendo para lo de antes —que sus facturas se imputen al local y que sus extraordinarios lo alcancen— y se avisa como informativo: *"[razón] es adicional de [local] pero no factura ningún concepto"*.
  - Una adicional **dada de baja** en Razones sociales no se lleva sus conceptos: **vuelven a la principal**, con su día y su diferimiento, y un aviso de atención nombra el local, la razón y los conceptos. Un alquiler no desaparece del flujo porque se dio de baja una razón. Quitar la adicional del local también los devuelve.
  - Una adicional que factura conceptos **sin día propio ni general** deja el local *Incompleto*, igual que la principal.
- El **mes del último ajuste es un mes del año**, no un año-mes: ancla el ciclo, y como las frecuencias dividen a 12 el dato no envejece. Trimestral con septiembre ajusta en diciembre, marzo, junio y septiembre.
- Se guarda **por fila**, como Parámetros › Tarjetas. Un trimestral sin mes del último ajuste **no se guarda** y la pantalla dice por qué.
- El filtro **Incompletos** muestra los locales que no proyectan, o no proyectan todo, por falta de algún dato.

| Estado | Cuándo | ¿Es incompleto? |
| --- | --- | --- |
| *Proyecta* | Tiene razón social dada de alta, frecuencia (y mes, si no es mensual), IVA, percepción, retención y un día de vencimiento | No. Un **local nuevo sin venta manual** proyecta fijos y expensas, pero no variables: figura en *Incompletos* con eso como faltante |
| *Incompleto* | Le falta algo de lo anterior | Sí |
| *Cerrado en Lakers* | `HABILITADO = 0` en `SUCURSALES_LAKERS` | **No**: no falta ningún dato, el local cerró. No se muestra ni en la Proyección ni en Ventas. Flores 1 es este caso |
| *Contrato finalizado* | Proyecta **según la vigencia** y su contrato venció en un mes anterior al actual | **No**. No se muestra, salvo que le quede algo por pagar (un variable diferido) |

### 3.d Pagos extraordinarios

Un % sobre uno o varios **conceptos base** (de los que se proyectan) de un **mes base** del año, que se paga en *mes base + diferimiento* y **se repite todos los años** hasta que se da de baja. Por razón social, con *todos los locales* (incluidos los que se le asignen después, como principal o adicional) o algunos. No se editan: baja y alta, para que el historial explique los meses ya proyectados. Lleva IVA, percepción y retención del local.

Ejemplo: Cencosud, 75 % del VMA (8) de diciembre, 1 mes de diferimiento: se paga en enero. Los conceptos base salen de la misma jerarquía de la parte 8: si diciembre está cerrado en la app, el VMA cerrado; si no, el proyectado. **El extraordinario es de su razón social**: se paga con su día y lo pisa una factura de ella (parte 13).

### 3.e Tipos de retención

`Class/AlquileresTiposRetencion.php` (script 60). Con estos tipos se cargan las retenciones de una imputación (parte 13), una línea por tipo. **Alta, edición del nombre y baja**, con quién y cuándo. El nombre es obligatorio y **único entre los vigentes sin distinguir mayúsculas ni acentos** (`validarNombre()`, y la columna `CI_AI` con su índice único filtrado). **Renombrar es baja y alta** (con `ID_REEMPLAZO`): una imputación vieja guarda el tipo con el que se cargó y sigue mostrando ese nombre. **Un tipo dado de baja** ya no se ofrece para imputar, pero las imputaciones que lo usan lo siguen mostrando. El script siembra, sin pisar, **Ganancias, IIBB Santa Fe, IIBB CABA e IIBB Bs As**.

---

## 4. Fijos: el ajuste por inflación

`AlquileresCalculo::fijos()`. El mismo criterio que el valor hora de Logística (`README-logistica-local.md`, sección 4):

- Base: el importe del **último período cerrado** del local.
- **Un fijo cargado en un período abierto** (parte 2) se usa en ese mes —origen *Cargado en app (abierto)*— y **es la base nueva**: los meses que siguen ajustan desde él, con la frecuencia y el mes de ajuste del local, como si fuera el último cerrado. Un fijo sin fila o en 0 se sigue estimando. Dos excepciones (`AlquileresCalculo::fijos()`):
  - **En un mes en que el local ajusta, si la app tiene el mismo valor que el último que se le tomó, no se usa**: la app copia el mes anterior al abrir el período, y ese valor todavía no tiene el ajuste. Se sigue estimando con el ajuste y un aviso informativo dice *"la app todavía no aplicó el ajuste de [mes] en [local]"*. Al 20/11/2026: Gurruchaga (semestral, ajusta en noviembre), San Justo y Tortugas (trimestrales).
  - **Si la app cambia un fijo en un mes que no es de ajuste, se usa, pero un aviso de atención** nombra el local y el mes y pide revisar el mes del último ajuste en Parámetros: si está mal, el próximo ajuste se suma dos veces. Al 20/11/2026: MDP Aldrey (trimestral con octubre) y Paseo del Siglo (bimestral con octubre) subieron en noviembre.
- Cada fijo estimado dice **desde qué mes ajusta** (el del último valor tomado de la app, cerrado o abierto): es el chip *Estimado · inflación desde [mes]* (parte 10).
- Con frecuencia N el local ajusta en los meses a múltiplos de N del mes del último ajuste. El % de un ajuste en el mes M es la **suma sin componer** de la inflación de los N meses que terminan en M (`Inflacion::sumada()`): trimestral en diciembre = oct + nov + dic. Mensual: la inflación de cada mes.
- **La base se corre**: cada ajuste va sobre el valor que dejó el anterior.
- Los ajustes empiezan el mes siguiente al último cerrado, así que **un ajuste entre el último cerrado y hoy también se aplica**.
- **Si falta la inflación de un mes se aplica lo que hay** y se avisa con `Inflacion::avisoFaltan()`. No es lo que hace Logística, que deja el mes en blanco, y es a propósito: un alquiler no deja de pagarse por un dato faltante, y en blanco desaparecería del cashflow.

---

## 5. Expensas (concepto 11)

`AlquileresCalculo::tasaExpensas()`. Por local, la **mediana de las variaciones mes a mes** de los últimos 24 meses cerrados, y la proyección mes a mes desde el último cerrado: `expensa(M) = expensa(M−1) × (1 + tasa)`.

- **Por qué la mediana y no la media geométrica.** La geométrica de los cocientes se reduce a (último / primero)^(1/n): sólo pesan las puntas, y un mes con una expensa extraordinaria en una punta cambia la proyección de todo el año. Avellaneda va de 3,1 a 10,4 millones dentro de la ventana. La mediana ignora esos picos.
- Sólo cuentan los pares de meses **calendario seguidos** con expensa mayor que cero: un cero o un mes sin dato no generan una variación de −100 % ni una infinita.
- Un local con **menos de 12 meses** de historia usa la **tasa general**: la mediana de las tasas de los locales con 12 o más. El tooltip dice la tasa, cuántos meses y si es la propia o la general.
- Al 10/11/2026: 17 locales con 24 meses, 3 con 23, Palmas 19, Flores 2 16, Malvinas 7, Rural y San Martín 0.

---

## 6. La venta por local

`Class/AlquileresVenta.php`. **Bruta = con IVA. Neta = bruta / (1 + alícuota de IVA de Generales)**, sin la relación histórica de cada local (que da 1,21 en todos, con menos de 3 % de diferencia).

| Origen | Cuándo |
| --- | --- |
| **Real** | El mes ya tiene venta en la app: neta de `RO_T_RENTABILIDAD_BRUTA` y bruta de `RO_V_VENTAS_BRUTAS_IE`. Si hay una y no la otra (hoy no pasa en ningún local), la que falta se deriva con el IVA general y el tooltip lo dice |
| **Estimada** | La venta proyectada del canal `LOCALES` de la pestaña Ventas, repartida por la participación de cada local en la venta neta real de los **últimos 12 meses cerrados** de la app |
| **Manual** | Un local **nuevo** (sin 12 meses de venta en esa ventana) usa la venta mensual estimada de Parámetros, si está |
| **Estimada (participación propia)** | Un **nuevo sin venta manual** con al menos **3 meses** de venta en la ventana (`MESES_MIN_PROPIA`) entra al reparto con su **participación propia**: su venta sobre la de los locales de 12 meses **en esos mismos meses**, para que la estacionalidad no lo infle ni lo achique. Se suma a las de los demás y se normaliza todo: la suma sigue cerrando contra el canal. Con menos de 3 meses, no proyecta variables y se avisa. Malvinas (8 meses) es este caso |
| **Real + proyectada** | **El mes en curso** con venta real: la real de la app (lo vendido hasta ayer) **más** la estimada —o la manual— del mes completo por la fracción de días que faltan, hoy incluido: `(días del mes − día de hoy + 1) / días del mes`. Antes el mes en curso tomaba como venta del mes lo vendido hasta el momento y los variables salían chicos. El tooltip separa la real de la proyectada. Al 17/11/2026 la venta neta de noviembre de los locales pasó de $ 454,0 M (lo vendido) a $ 1.091,4 M, y el total del horizonte subió $ 1,13 M (los variables de noviembre se pagan diferidos). Supone que la real de la app llega hasta ayer: `RO_T_RENTABILIDAD_BRUTA` es mensual y no dice hasta qué día está cargada |

- **La venta del canal es la del mes completo**, `Ventas::ventaMensualCanal()`: la base mensual (`baseMensual()`) por la participación mensual del canal. No es la grilla diaria de Ventas, que en el mes en curso arranca hoy y en los primeros 28 días usa la participación del tramo: los porcentajes del alquiler son sobre el mes entero. Al 10/11/2026, noviembre da **$ 1.652,6 M** contra los $ 1.269,6 M que muestra Ventas desde hoy, y los 12 meses suman **$ 22.372 M**. No se recalcula: se le pide a Ventas.
- **Las manuales se restan del canal antes de repartir**, así que *estimadas + manuales = canal* al centavo. El redondeo sobrante va al local con más participación. Lo fija una prueba, y el pie de la sub-pestaña Ventas muestra la diferencia, que da 0 en los meses estimados.
- **Un local cerrado en Lakers no entra al denominador.** Un local *incompleto* pero abierto sí reparte: sigue vendiendo.
- Para los variables diferidos se calculan también los meses de venta **anteriores al horizonte** que se pagan adentro: con diferimiento 2, la venta de dos meses atrás. Esos meses no tienen venta proyectada del canal: si a algún local le falta la real, **el canal se arma con la venta real de los que la tienen, dividida por su participación**, y el que falta se estima con la suya (el tooltip lo dice). Sólo queda sin venta, con un aviso que lo nombra, un local sin venta en la app, sin participación y sin venta manual **que tenga conceptos variables**: OESTE no tiene ventas, pero tampoco porcentajes de variables, así que no se avisa.

---

## 7. Los conceptos variables

`AlquileresCalculo::variables()`, con las fórmulas de la app (`cargarAlquieres()` en el controller y `totalizar()` en el JS), verificadas contra los cierres:

| Concepto | Fórmula |
| --- | --- |
| 6 | `max(0, venta bruta × %6 − VMA)` |
| 7 | `max(0, venta neta × %7 − VMA)` |
| 14 | `concepto 7 (ya con el VMA restado) × %14` |
| 16 | `max(0, venta bruta × %16 − concepto 9)` |
| 17 | `max(0, venta neta × %17 − concepto 9)` |

El VMA y el 9 son los **del mismo mes de la venta**. Un local sin porcentaje para un concepto no lo proyecta. El 14 con %14 y sin %7 da 0, como en la app. Los porcentajes se leen con el mismo parseo que la app (`str_replace(',', '.')`), aunque hoy la columna sea `FLOAT`.

---

## 8. La jerarquía: qué pisa a qué

Por local y por mes, de mayor a menor (`AlquileresCalculo::ORDEN_ORIGEN`). **El origen es por concepto**: en un mismo mes el VMA puede venir de la app, el 6 de la venta real y las expensas estimadas.

1. **Factura** — la factura imputada. **No se resuelve en `conceptosDelMes()`**: una imputación pisa **todo lo de una razón social en sus locales en un mes de pago**, con el importe final, y ese cálculo es por mes del concepto y en neto. La aplica `AlquileresImputacion` sobre la lista de pagos (parte 13).
2. **Real (cerrado)** — el período está cerrado en la app: sus importes por concepto, tal cual, incluidos los variables.
3. **Cargado en app (abierto)** — el período está abierto y el fijo (1, 2, 3 u 8) está cargado: ese importe (parte 4). El 9 calculado con ese VMA también lleva este origen. Los variables de ese mes se calculan con el VMA y el 9 de la app; las expensas, con su tasa.
4. **Venta real** — un variable calculado por el cashflow con la venta real del mes.
5. **Estimado** — todo estimado; un fijo dice desde qué mes ajusta por inflación.

---

## 9. Cuánto se paga y cuándo

`Class/AlquileresPago.php` es **la** regla, y la usan la pestaña y el proveedor del tablero.

```
neto        = importe del concepto (o del extraordinario)
IVA         = neto × % IVA del local
percepción  = neto × % percepción del local
retención   = neto × % retención del local      ← sobre el NETO
bruto       = neto + IVA + percepción            ← lo que se compara con una factura
a pagar     = neto + IVA + percepción − retención
```

El **bruto** es lo que muestran los grupos y los conceptos de la Proyección (parte 10); el **a pagar**, lo que sale de caja, es lo de la razón social, el local, el tablero y el pie. En una factura imputada, bruto = su importe.

- **Mes de pago**: fijos, fondo y expensas, el mismo mes; variables, el mes de la venta + el diferimiento **de la razón social que los factura** (parte 3.c); extraordinarios, el mes base + su diferimiento.
- **Día**: el de **la razón social que factura el concepto** —o la del extraordinario—, o el general. En San Justo, el VMA vence el día de SASANJ y las expensas el de SAUCCS. Cada pago lleva su `cod_provee`. Si no es hábil, el siguiente hábil (`DiasHabiles::siguiente()`) y la celda lo anota con `detalle`.
- **Un vencimiento que ya pasó en el mes en curso** —todavía sin factura— va al **siguiente hábil desde hoy**, nunca apilado en el primer día del eje. Es provisorio: una factura imputada que cubre ese mes lo reemplaza, en su propia fecha.
- **Vencimiento del contrato** (la fecha de fin de Parámetros): no corta; ver 3.c.
- Cada parte se redondea a centavos una sola vez. La app trunca a pesos; como los meses cerrados se leen tal cual, eso no cambia nada de lo cerrado.

---

## 10. La pestaña

`Class/Alquileres.php` (arma, sin reglas propias), `Controller/AlquileresController.php`, `Tabs/alquileres.php`, `Js/Alquileres.js`. Las dos sub-pestañas salen de **un** pedido (`getProyeccion`).

### Proyección

- **Primer nivel: la razón social** (nombre y código), con su total por mes. **Debajo, sus locales**; debajo de cada local, los grupos —*Fijos, Fondo de promoción, Expensas, Variables y Extraordinarios*—, y debajo los conceptos, **con IVA y percepción, sin restar la retención** (el `bruto` de la parte 9). Razón social y local muestran el **a pagar**, con la retención estimada del local ya restada (`Alquileres::armarRazones()`). Lo dice una línea arriba de la leyenda de los chips. Hasta el 20/11/2026 grupos y conceptos iban con el **neto** y parecía que no tomaban el IVA y la percepción de Parámetros: subieron en esa proporción (≈ 21 % más la percepción), también en el Excel. El total del local, de la razón social, el pie y el tablero no cambiaron.
- **Un local con dos razones sociales aparece bajo las dos**, cada una con lo que ella factura: San Justo bajo SASANJ con el 8 y el 9, y bajo SAUCCS con el resto. Bajo la principal se ve siempre, con su estado; bajo una adicional, si le factura algo en el horizonte. Los locales sin razón social dada de alta van al final, en **"Sin razón social"**.
- **Al final de cada razón social, "Facturas imputadas"**: el total a pagar de sus facturas en cada mes de pago, sin reparto entre locales (parte 13). El tooltip dice cada factura (comprobante, pendiente, vencimiento a considerar y fecha de pago), las retenciones por tipo, el total a pagar, el mes y los locales que cubre (o *todos*) —*"cubre [mes], fuera del horizonte"* si es un mes que ya pasó—, quién la cargó y cuándo.
- Un concepto fijo que vale 0 en todo el horizonte no se muestra (no cambia ningún total); un variable en 0 sí, porque dice que la venta no supera el VMA.
- **El origen de cada importe**: cada celda lleva un chip —*Real (cerrado)*, *Cargado en app (abierto)*, *Venta real*, *Estimado · inflación desde [mes]*, *Estimado* y, en la línea de facturas, *Factura*—, con la leyenda arriba de la grilla. Un subtotal (grupo, local, razón social) con un solo origen lleva ese chip; con varios, **"mixto"**, y el tooltip abre el desglose. El tooltip de toda celda termina con *"Origen: …"*. `Alquileres::origenDelPago()` es la regla. **Los chips van en grises**, en `data-etiqueta` y dibujados por el CSS: no tapan el importe y el Excel se queda con el número limpio.
- El tooltip de cada celda: neto, IVA, percepción, con IVA y percepción, retención, a pagar, día de pago (y si se corrió), origen y, según el grupo, la venta usada con su origen, el % y el mes de la venta; el % de ajuste y su ventana; la tasa de las expensas; la regla del 9; la base del extraordinario.
- El mes es el **mes de pago**: un variable diferido aparece donde se paga y el tooltip dice de qué mes es la venta.
- Columnas fijas (razón social · local y código), totales arriba del eje, orden por encabezado (locales, grupos y conceptos viajan pegados a su razón social con `data-orden-sigue`; `data-ancestros` dice qué tiene que estar abierto para verlos), buscador por razón social, código o local, y **Exportar con todo abierto**: `tabla-export.js` baja lo que se ve, así que el botón abre todo, exporta y vuelve. **El Excel baja el origen** en una columna *Origen mm/aa* al lado de cada mes, que el botón agrega sólo mientras exporta: en pantalla correría los totales de arriba del eje, que piden una celda por columna.
- Tarjetas: a pagar en el horizonte, mes en curso, locales que proyectan y locales incompletos, con el link a Parámetros › Alquileres con el filtro *Incompletos* puesto.
- Avisos: locales incompletos, sin período cerrado, sin porcentajes, sin venta, inflación faltante, razones sociales que perdieron el rubro, calendario faltante, fijos de la app sin el ajuste o ajustados fuera de mes, adicionales sin conceptos o dadas de baja.

### Ventas

Los locales **cerrados en Lakers no se muestran** (tampoco en la Proyección, que ya no lleva la tarjeta de cerrados). Neta o bruta por local y mes con su origen (chip *Real*, *Estimada*, *Manual*, *Real + proyectada*), incluidos los meses anteriores al horizonte que pagan los diferidos. La primera columna es el **número de sucursal** y la grilla viene **ordenada por él**; el encabezado cambia el orden. Al pie, el **total de los locales** en todos los meses (neta o bruta, según el selector: antes los meses sólo con venta real, como septiembre y octubre, no totalizaban) y, como control del reparto, estimadas + manuales del mes completo, la venta del canal Locales y la diferencia (0 en los meses estimados). El tooltip de una estimada dice la participación del local y la venta del canal; la sub-pestaña, el período de la participación.

### Facturas

Ver parte 12.

---

## 11. Las facturas reales y el tablero

Lo que la parte A dejó pendiente llegó con `feature/alquileres-facturas-exclusion`: las partes 12 a 15.

---

## 12. Las facturas: cuáles son y de dónde salen

`Class/AlquileresFacturas.php` (la regla, pura) y `Class/AlquileresUniverso.php` (lee su contexto, una vez por pedido).

**Una factura es de alquiler si su proveedor está dado de alta (vigente) en Parámetros › Alquileres › Razones sociales.** El rubro `ALQUILER` del maestro no decide nada. `AlquileresFacturas::destino()` dice a dónde va cada fila de `Proveedores::getPendientes()`: a Alquileres, a Proveedores Locales, a **Llaves y Renovaciones** (`A_LLAVES`: la cuota se marcó como llave o renovación, parte 14.b) o a ningún lado (excluida de Alquileres). La usan Proveedores Locales (grilla, tarjetas, exportar, planilla de pagos y su proveedor), Tarjetas, la pestaña, Llaves y Renovaciones y el tablero; una prueba fija que digan lo mismo.

**Una sola consulta a Tango**: el universo sale de `getPendientes()`, la misma de Proveedores Locales. (Hasta el 20/11/2026 traía también el neto gravado del comprobante, para sugerir la retención de la imputación por renglones; con las retenciones por tipo dejó de usarse y salió.)

### La condición de seguridad

Las facturas salen de Proveedores Locales **sólo si** el módulo `ALQUILERES` está disponible, hay una **fila activa del tablero con `ALQUILERES` / `REAL` o `PAGOS`** y existe la tabla del script 53 (`RO_T_CASHFLOW_ALQ_FACTURA_VTO`, `AlquileresFacturas::TABLA_CONDICION`). Si falta cualquiera, se quedan en Proveedores Locales **para todos** —tampoco la pestaña Alquileres las toma— y un aviso **crítico** dice qué falta. Sacarlas antes las haría desaparecer del cuadro.

Hasta el 20/11/2026 la condición miraba la cabecera de las imputaciones por renglones, que el script 60 borra: si siguiera mirándola, correr el 60 devolvería todas las facturas a Proveedores Locales. Sin el 60, las facturas quedan en Alquileres sin imputar, que no suman, y la pestaña avisa que no se puede imputar.

### La grilla

Una fila por **vencimiento** (cuota): razón social, comprobante, emisión, vencimiento de Tango, **vencimiento a considerar**, importe, pendiente, retención —**la parte de las retenciones de su imputación que le tocó**, con los tipos en el tooltip—, **a pagar** y estado (*Sin imputar*, *Imputada*, *Imputada parcialmente* —otra cuota del comprobante sin imputar—, *Pagada*, *Pagada, sin imputar*, *Ya no está en Tango*, *Sin dato de Tango*, *Imputación suspendida*, *Excluida*, *Llave / renovación* y, al pedirlas, *Pagada, de un mes anterior*). Filtros por razón social y estado, *Ver pagadas de meses anteriores* (las del mes en curso se ven siempre; las de los 12 meses anteriores se piden aparte, para marcarlas como llave), *Ver excluidas* (también las marcadas como llave), totales, exportar y cuatro tarjetas: **sin imputar** (cantidad e importe: plata que no suma en ningún lado), imputadas a pagar, pagos proyectados reemplazados y **excluidas** (cantidad e importe).

### El vencimiento a considerar

**Es la única fecha de pago** de una factura de alquiler: la planilla de pagos de Proveedores Locales deja estas facturas en error. La jerarquía (`AlquileresImputacion::vtoAConsiderar()`):

1. **La fecha manual**, cargada en la grilla (no hace falta el script 60: se edita aunque todavía no se pueda imputar). Mismo patrón que Tarjetas (`AlquileresFacturaVto`, tabla propia): input que guarda al cambiar, fecha mínima hoy (`TarjetasCorporativas::validarVtoEditado()`, la misma regla), por cuota, con historial y motivo opcional. **↺ la quita** y vuelve a la de Parámetros; elegir a mano la fecha de Parámetros también.
2. **La de Parámetros**: el día de vencimiento de la razón social (o el general) **en el mes del vencimiento de Tango** (`vtoDeParametro()`). Ejemplo: FAC A0005000027187 de SAGALL (día 18) vence en Tango el 20/11/2026 y se considera el **18/11**.
3. La de Tango, sólo si la razón social no tiene día y no hay general (chip *Tango* en la celda).

El tooltip de la celda dice de cuál sale. Al 17/11/2026 cambiaron 2 de las 4 cuotas pendientes: SAGALL A0005000027187 (20/11 → 18/11) y SACORR A0000200000467 (18/11 → 15/11, que por vencida se paga el siguiente hábil desde hoy).

**Vencida**: una imputada con vencimiento a considerar anterior a hoy se paga el **siguiente día hábil desde hoy** (`AlquileresImputacion::fechaDePago()`), y la celda lo anota con `detalle`.

---

## 13. La imputación

`Class/AlquileresImputacion.php` es **la** regla de qué reemplaza una imputación, pura; `Class/AlquileresImputaciones.php` lee y escribe (script 60).

### Qué se imputa

**Una o varias cuotas de UNA razón social**, a **todos sus locales** (opción por defecto: donde es principal y donde es adicional, `razonesDelLocal()`) o a **uno**, en un **mes** —el mes de pago cuya proyección se reemplaza: por defecto el mes en curso; se puede elegir el anterior o cualquiera del horizonte—, con sus **retenciones por tipo** (*+ Retención*: un tipo vigente de la parte 3.e y un importe; un tipo no se repite; sin retenciones es válido) y una observación opcional. **No se eligen conceptos.**

- **Al tildar la primera factura, las de otras razones sociales quedan deshabilitadas**, con el porqué en el tooltip. El servidor lo vuelve a validar.
- **Tildar todas**: la casilla del encabezado tilda (o destilda) las **sin imputar** —también las *Pagada, sin imputar*— **que se ven con el filtro**. Se habilita sólo si son todas de una razón social (en la práctica, filtrando por una) y, si ya hay algo tildado, de esa misma. Las imputadas, parciales o suspendidas no las toma: se tildan a mano, pero el servidor las rechaza. Queda a medias (indeterminada) si hay algunas tildadas.
- **Totales en vivo**: total facturas (el pendiente de las cuotas tildadas, con IVA y percepciones), total retenciones y **total a pagar = facturas − retenciones**. Las retenciones no pueden superar las facturas (iguales, sí: a pagar 0).
- **La previsualización y el guardado son la misma validación** (`validar()`), contra los pendientes de ahora (`Alquileres::contextoImputacion()`): si una factura cambió de pendiente o ya no está, frena. La previsualización dice además cuánto proyectado pisa.
- **Dos imputaciones vigentes no pueden pisar lo mismo**: misma razón social, mismo mes y algún local en común da error, nombrando la otra. **Con locales distintos se puede** (una factura por local). Al guardar se vuelve a mirar dentro de la transacción, con las cabeceras de esa razón y ese mes bloqueadas.
- **Una cuota ya imputada no se imputa otra vez**: *"deshacé primero la #N"*. **Para corregir una imputación se deshace y se arma de nuevo**; no se edita. **Deshacer** devuelve las facturas a *Sin imputar* y la proyección vuelve. Sin bajas físicas.
- **"Todos los locales" se resuelve al guardar**: se guarda la lista con la marca. La factura es un hecho de ese momento: un local que se agregue después a la razón no cambia una imputación ya hecha.
- **Una factura excluida no se imputa**, ni una imputada se excluye (parte 14.b).

### Qué reemplaza y cuándo se paga

- **Pisa todo lo proyectado de esa razón social, en esos locales, en ese mes de pago**: fijos, fondo, expensas, variables y extraordinarios. *De esa razón social* es lo que ella factura en cada local (parte 3.c) y sus extraordinarios. **Manda lo real**: no queda saldo, sea mayor o menor que lo proyectado.
- **Lo que no se imputó sigue proyectado**: los otros meses, los otros locales (si se eligió uno) y lo que otra razón social factura en el mismo local (San Justo: una factura de SAUCCS no pisa el VMA de SASANJ).
- **Un mes que ya pasó** (el anterior) no pisa nada del horizonte: la factura se paga igual en su fecha, y el tooltip dice *"cubre [mes], fuera del horizonte"*.
- **Cada cuota se paga en su vencimiento a considerar** (parte 12), corrido al siguiente hábil, y si ya pasó, el siguiente hábil desde hoy. Paga **su pendiente menos su parte de las retenciones**: el total de retenciones se reparte entre las cuotas en proporción a su pendiente, por resto mayor al centavo (`repartirCentavos()`), y las partes suman exacto el total a pagar.
- **Sin reparto entre locales**: la factura es de la razón social. En la Proyección va en la línea **"Facturas imputadas"** al final de su razón social, y en los locales que cubre ese mes queda sin proyección (parte 10). En el tablero, `REAL` (parte 15).

### Sin imputar, pagada, cambiada

- **Las facturas del mes, pagadas o no.** Además de las pendientes, la grilla trae las cuotas **emitidas en el mes en curso y ya canceladas en Tango** de las razones dadas de alta (`Proveedores::getCanceladasEmitidas()`: CPA54 con `ESTADO_VTO = 'PAG'`, comprobantes que suman deuda, `CPA21.CRE_DEB = 'D'`). Las que nadie imputó quedan **Pagada, sin imputar**: no se pagan otra vez, pero **la proyección del mes sigue en el flujo** hasta que se imputen. Se tildan e imputan como las demás, **por el importe de la cuota** (`AlquileresImputacion::comoPendiente()`); en la próxima carga Tango las da canceladas, quedan *Pagada* y **reemplazan** la proyección del mes: **para que el monto desaparezca del flujo, la factura tiene que estar imputada y pagada**. Aviso de atención con la cantidad y el importe, y la tarjeta *sin imputar* lo suma al pie. No se les cambia el vencimiento.
- **Sin imputar no suma** —ni en la pestaña ni en el tablero—: contaría dos veces contra la proyección. Aviso de atención: *"N factura(s) de alquiler sin imputar por $ X: no suman hasta que se imputen"*.
- **Pagada en Tango**: sale de pendientes; se marca *Pagada* con la fecha de cancelación al cargar la pestaña (usuario del sistema, como la conciliación de Proveedores Locales, con `Proveedores::estadoComprobantesEnTango()`). **Sigue reemplazando** el mes. Se ve con *Ver pagadas*.
- **Desaparecida sin pago** (anulada) o sin dato de Tango: **deja de reemplazar**, la proyección vuelve y se avisa para imputar la nueva. Con varias facturas, cuando **todas** quedaron así.
- **Pendiente que cambia sin cancelarse**: se paga el pendiente nuevo menos su parte de las retenciones, y se avisa.
- **Suspendida**: una imputación con una factura de un proveedor excluido de Alquileres o dado de baja no se lee; se avisa para deshacerla.

### Las imputaciones viejas

Hasta el 20/11/2026 una imputación se repartía en **renglones** de conceptos × locales × mes, con atajos por grupo, *Otros (no proyectados)*, *Extraordinario* como concepto, una retención por factura sugerida con el % de los locales y un reparto entre locales en proporción a lo proyectado. **Se borró todo**: el código, sus pruebas y —con el script 60— sus cinco tablas y sus datos. No se migraron: un renglón de conceptos no se traduce a *toda la razón social en esos locales* sin decidir por el usuario. Ver *Después de correrlos*, arriba, para las dos facturas a reimputar.

---

## 14. La exclusión de proveedores por módulo

`ProveedoresExclusion` suma **`TARJETAS_CORP`** y **`ALQUILERES`** a `PROV_LOCALES` (script 54). El alta en Parámetros decide si un proveedor va a Alquileres; la **exclusión** lo saca de esa pestaña y del tablero, a propósito y con motivo. **Excluido de Alquileres**: sus facturas no entran, no se imputan, no suman y **no vuelven a Proveedores Locales**; no toca la proyección de sus locales. Un aviso informativo dice cuántas y cuánto.

Se edita en la solapa **Maestro** de Proveedores Locales, una columna por módulo, **cada una con el permiso de su módulo** (`AuthCashflow::TAB_DEL_MODULO`).

### 14.b Una factura que no es un alquiler: llave o renovación, o excluida

Hay facturas de las razones de alquiler que no son alquiler. **Los pagos de contrato** —la «cuota inicial» de IRSA, la llave de Cencosud, las comisiones, el FPC de lanzamiento, una obra— **se marcan «Es llave / renovación»** y los cuenta Llaves y Renovaciones; **se excluyen** sólo las que no se pagan por ningún circuito del cashflow.

#### Es llave / renovación

`Class/LlavesFacturas.php` (script 68). En *Alquileres › Facturas*, junto a *Excluir*, **por cuota o las tildadas**: se elige el **local** (cualquiera: IRSA factura el FPC de DOT), el **concepto** (Llave / Otros gastos / Obra) y el **mes**; de a una factura pendiente, en cuántos **pagos** se paga (la factura no es el pago). Lo que vale es la previsualización del servidor.

- **Sale de Alquileres y no vuelve a Proveedores Locales**: `AlquileresFacturas::destino()` devuelve `A_LLAVES`, la misma regla para todos. En Llaves reemplaza todo lo proyectado de ese local y concepto en esa renovación y suma como real. Ver `README-llaves-renovaciones.md`, parte 4.
- Se marcan las *Sin imputar*, las *Pagada, sin imputar* y, con *Ver pagadas de meses anteriores*, las **pagadas de los últimos 12 meses** (*Pagada, de un mes anterior*): una pagada no suma y corta la estimación. **No se marca una imputada ni una excluida.**
- **Ver excluidas** muestra también las marcadas: estado *Llave / renovación*, con el local, el concepto, el mes, quién y cuándo en el tooltip. **Deshacer** es baja lógica y la cuota vuelve a *Sin imputar*.

#### Excluir

`Class/AlquileresFacturaExclusion.php` (script 61). Las que **no se pagan por ningún circuito del cashflow** se excluyen **por cuota o las tildadas**, con **motivo obligatorio** (lo valida el servidor y el `CHECK`). Llaves y Renovaciones avisa cuántas hay excluidas, para reclasificar como llave las que lo sean (al 23/11/2026 no había ninguna).

- **Una cuota excluida no suma en ningún lado**: ni en Alquileres, ni en el tablero, ni **vuelve a Proveedores Locales**. Lo decide `AlquileresFacturas::destino()` (`A_EXCLUIDA_FACTURA`), la misma regla para todos; Proveedores Locales lo dice en su aviso. Con la condición de seguridad sin cumplir, todo se queda en Proveedores Locales, como siempre.
- Se excluyen las *Sin imputar* y las *Pagada, sin imputar*. **No se excluye una imputada** (primero se deshace) **ni se imputa una excluida**.
- **Ver excluidas** las muestra; el estado dice *Excluida*, con motivo, usuario y fecha en el tooltip. **Volver a incluir** es baja lógica, con historial, y la cuota vuelve a *Sin imputar*. El índice único filtrado impide dos exclusiones vigentes de la misma cuota.
- Una tarjeta con la cantidad y el importe excluidos, y un aviso informativo.

---

## 15. El tablero

`ALQUILERES` disponible, tres series de **la misma lista de pagos** que agrupa la pestaña (`AlquileresProvider::armarSeries()`):

| Serie | Qué es |
| --- | --- |
| `REAL` | Las facturas imputadas, en su fecha de pago, por su pendiente menos su parte de las retenciones |
| `PROYECTADO` | La proyección que ninguna factura reemplazó |
| `PAGOS` | Las dos juntas: la fila de antes del split. `componentes` impide que conviva con las partes |

`sql/cashflow_alquileres_fila.sql` reemplaza la fila *Alquileres* de Costos Indirectos por **dos filas agrupadas** (`GRUPO` = ALQUILERES): un renglón que se abre en real y proyectado. **La pestaña y el tablero suman lo mismo, por día**, y lo fija una prueba.

**Antes y después, al 10/11/2026.** *Cuentas a Pagar Locales* (`PAGOS`) no tenía nada de alquiler: las cuatro razones dadas de alta estaban excluidas de Proveedores Locales, en `PAGOS_EXCLUIDOS_PROVEEDOR`. Con el script 53, sus facturas pendientes (3 de Cencosud, $ 43.944.604,50) pasan a `PAGOS_ALQUILERES` y a Alquileres, **sin imputar**: no suman. Lo que mueve el Flujo Neto es la **proyección**, que entra al tablero con el código: a las 21:40 de ese día, $ 310.535.553,98 en el horizonte.

---

## Permisos

| Clave | Para qué |
| --- | --- |
| `cashflow.tab.alquileres` | Ver la pestaña (`getProyeccion` la pide) |
| `cashflow.editar.parametros.alquileres` | Todas las escrituras de Parámetros › Alquileres, incluidos los tipos de retención y los conceptos de cada razón social |
| `cashflow.editar.alquileres` | Facturas: el vencimiento a considerar, imputar y deshacer, excluir y volver a incluir, marcar como llave y deshacer la marca; y la columna *ALQUILERES* de la exclusión en el Maestro de Proveedores Locales |

Las tres van en `sql/cashflow_alquileres_permisos.sql` y **no** en `sql/cashflow_permisos_edicion.sql`, porque ese le da la edición a todo rol que ve la pestaña. Toda escritura pasa por `AuthCashflow::usuarioDeEscritura()`. Sin permiso de edición, los controles no se dibujan. Marcar *Pagada* lo hace el sistema (`AlquileresImputaciones::usuarioSistema()`).

---

## Pruebas

```
php tests/run.php alquileres
```

- `test_alquileres_app.php` — los períodos y el parseo de porcentajes; que la clase no tenga ni una sentencia de escritura; contra la base, que `detalleCerrados()` no lea meses abiertos, que `detalleAbiertos()` traiga sólo fijos, el VMA de noviembre de 2026 de Avellaneda ($ 10.209.263, mientras siga abierto) y que en el último cerrado el 6 y el 7 ya tengan restado el VMA.
- `test_alquileres_calculo.php` — la constante de grupos; los fijos de un período abierto (usado, base nueva, sin el ajuste, ajuste fuera de mes, en 0) y Avellaneda octubre (el 6 con el VMA de la app, ±$ 1); las cuatro frecuencias con inflación variable (meses de ajuste, suma sin componer, base corrida), el ajuste entre el cierre y hoy, la inflación faltante; el 9; las expensas con 24 meses, con ceros y huecos, y con 8 (tasa general); los cinco variables por encima y por debajo, el 14 sobre el 7 neto, un cierre real de Tortugas; la jerarquía.
- `test_alquileres_venta.php` — real sobre estimada, neta = bruta / 1,21, el reparto al centavo con y sin manuales, el nuevo sin manual, el cerrado fuera del denominador, la fecha de fin, la venta derivada.
- `test_alquileres_pago.php` — qué factura cada razón social (por defecto la principal, la adicional se lleva lo suyo, la dada de baja lo devuelve) y el día y el diferimiento de cada una; impuestos con retención sobre el neto e IVA de 23 % y 19 %; fin de semana, feriado, vencido en el mes en curso; diferimiento 0 y 2 con venta anterior al horizonte; día de la razón sobre el general; el extraordinario de Cencosud (todos y subconjunto, repetición anual, diciembre cerrado); fecha de fin; cada faltante que deja un local incompleto.
- `test_alquileres_parametros.php` — validadores (también los conceptos por razón social y los tipos de retención), el universo por la columna `RUBRO`, el cableado de la sub-pestaña y su permiso, y que sin el script carga con el aviso que lo nombra.
- `test_alquileres_pestana.php` — de punta a punta con dobles: la grilla por razón social (San Justo bajo las dos), la línea *Facturas imputadas*, los chips de origen y el mixto; el diferido de IRSA, el extraordinario, quién no proyecta, que la pestaña sume la lista de pagos, el pie de Ventas; **REAL + PROYECTADO = PAGOS = la pestaña, por día**; la fila agrupada contra el validador.
- `test_alquileres_facturas.php` — qué factura es de alquiler (no el rubro), la condición de seguridad, las series y particiones de Proveedores Locales, la planilla en error, Tarjetas, una sola regla, el día del pase, los de rubro fuera de toda fila.
- `test_alquileres_imputacion.php` — todos los locales / un local / San Justo (SAUCCS no pisa a SASANJ); facturas de dos razones; superposición y una por local; cuota ya imputada; retenciones (total, tipo repetido, mayores al total, iguales, reparto al centavo); real menor y mayor; pagada, pagada sin imputar, anulada, sin dato, pendiente cambiado, suspendida; deshacer; mes fuera del horizonte; el modelo de renglones ya no está; el script 60 y el 53; el cableado.
- `test_alquileres_exclusion.php` — `MODULOS` y los `CHECK`; cada columna con su permiso; Tarjetas sin el proveedor y con la exclusión por factura; la factura de alquiler excluida (no suma, no vuelve a Proveedores Locales, no se imputa, volver a incluir, motivo obligatorio, el script 61).
- Cableado en las existentes: `test_auth_cashflow.php` (los dos scripts de permisos, la clave reservada, 16 controllers), `test_tablas_controles.php` (el selector de conceptos, la sección Tipos de retención, la grilla por razón social, la línea *Facturas imputadas*, los chips y la leyenda), `test_parametros_carga.php`, `test_avisos.php`, `test_cargando.php`, `test_kpis_plegables.php`.

---

## Archivos

| Archivo | Qué es |
| --- | --- |
| `Class/AlquileresConceptos.php` | La lista de qué concepto va en qué grupo |
| `Class/AlquileresApp.php` | La lectura de la app de alquileres (sólo lee) |
| `Class/AlquileresCalculo.php` | Puro: fijos, 9, expensas, variables, jerarquía |
| `Class/AlquileresVenta.php` | Puro: la venta por local |
| `Class/AlquileresPago.php` | Puro: cuánto y cuándo; el estado de un local |
| `Class/AlquileresParametros.php` | Parámetros › Alquileres: lectura, escritura y validadores |
| `Class/Alquileres.php` | Arma Parámetros y la pestaña; la lista de pagos que lee el tablero |
| `Class/Providers/AlquileresProvider.php` | Las series `REAL`, `PROYECTADO` y `PAGOS` |
| `Class/AlquileresFacturas.php` | Puro: qué factura es de alquiler, la condición de seguridad, los avisos |
| `Class/AlquileresUniverso.php` | Lee el contexto de esa regla, una vez por pedido |
| `Class/AlquileresImputacion.php` | Puro: validar, qué pisa una imputación, repartir las retenciones, fechas, pagada |
| `Class/AlquileresImputaciones.php`, `Class/AlquileresFacturaVto.php` | Leen y escriben las imputaciones y los vencimientos |
| `Class/AlquileresTiposRetencion.php` | Los tipos de retención: lectura, escritura y el validador del nombre |
| `Class/AlquileresFacturaExclusion.php` | Las facturas excluidas: lectura, escritura y el validador |
| `Js/Alquileres-Facturas.js` | La sub-pestaña Facturas y el armado de una imputación |
| `Controller/AlquileresController.php` | Los endpoints de las dos pantallas |
| `Tabs/alquileres.php`, `Js/Alquileres.js`, `Css/Alquileres.css` | La pestaña |
| `Tabs/parametros_alquileres.php`, `Js/Parametros-Alquileres.js` | La sub-pestaña de Parámetros |
| `sql/cashflow_alquileres.sql`, `sql/cashflow_alquileres_permisos.sql` | Los scripts 51 y 52 |
| `sql/cashflow_alquileres_facturas.sql`, `sql/cashflow_prov_exclusion_modulos_ampliar.sql`, `sql/cashflow_alquileres_fila.sql` | Los scripts 53, 54 y 55 |
| `sql/cashflow_alquileres_local_razones.sql` | El script 58: las razones sociales adicionales de un local |
| `sql/cashflow_alquileres_local_vigencia.sql` | El script 59: proyectar según apertura o vigencia del contrato |
| `sql/cashflow_alquileres_imputacion_razon.sql` | El script 60: tipos de retención e imputación por razón social (borra las imputaciones viejas) |
| `sql/cashflow_alquileres_excluir_factura.sql` | El script 61: las facturas excluidas |
| `sql/cashflow_alquileres_local_razon_conceptos.sql` | El script 62: qué conceptos factura cada razón social adicional de un local |

---

## Pendientes conocidos

- **La app de alquileres filtra la venta con `LIKE`.** `traerRentabilidadBruta()` busca `PERIODO LIKE '%M-YYYY%'`, así que en **enero** `'%1-2026%'` levanta también noviembre (`'11-2026'`) y en **febrero** `'%2-2026%'` levanta diciembre (`'12-2026'`), y como recorre todas las filas se queda con la última. El cashflow compara exacto. No se toca en esta rama: es de `ProyectosXL/administracion`.
- **La ayuda de la app dice que el 9 es un importe fijo**, y el código lo calcula como `VMA × %9`. Habría que corregir la ayuda en la app.
- **Los períodos 6-2022 a 12-2022 nunca se cerraron** en la app. Quedan fuera de las ventanas del cashflow (24 meses), así que no cambian nada.
- **Mercado Libre y Sploder tienen `RUBRO = ALQUILER`** en el maestro. Aparecen en el buscador pero no están dados de alta; si alguno se diera de alta por error, sus facturas saldrían de Proveedores Locales.
- **Al 10/11/2026 hay 13 proveedores de rubro ALQUILER excluidos de Proveedores Locales y sin dar de alta acá** (más Mercado Libre y Sploder): su deuda no está en ninguna fila. Lo avisa la parte 3.b; se resuelven desde la pantalla.
- **El panel de avisos es texto**: el link al Maestro está en la lista de Parámetros › Alquileres, no adentro del aviso del tablero.
- **Rural, Flores 2 y Palmas** tienen sus fijos en 0 en el último cierre: proyectan 0.
- **Reimputar SACORR A0000200000467 y SAARCO A0001300017366** después del script 60: hasta entonces noviembre suma $ 42.294.950,15 de proyección que ya se pagó.
- **MDP Aldrey y Paseo del Siglo**: la app subió sus fijos en noviembre, que según Parámetros no es mes de ajuste (trimestral y bimestral con octubre). Hay que revisar su mes del último ajuste, o el próximo ajuste se suma dos veces. Lo avisa la pestaña.
- **Gurruchaga, San Justo y Tortugas**: noviembre es su mes de ajuste y la app todavía tiene el valor de octubre; se estima con el ajuste hasta que la app lo cargue.
