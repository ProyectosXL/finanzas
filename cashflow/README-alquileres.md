# Módulo Alquileres — cuánto se paga de alquiler por local, y cuándo

Pestaña **RRHH y Operativos → Alquileres** (sub-pestañas *Proyección*, *Ventas* y *Facturas*), la sub-pestaña **Parámetros › Alquileres** y el proveedor `ALQUILERES` del tablero, en una fila agrupada en **real** y **proyectado**.

Ramas: `feature/alquileres-proyeccion` (la proyección, partes 1 a 11) y `feature/alquileres-facturas-exclusion` (las facturas reales, la imputación, la exclusión por módulo y el tablero, partes 12 a 15).

---

## La idea en una línea

**El alquiler de cada local se proyecta desde la app de alquileres de administración: los meses cerrados se toman tal cual, los fijos se ajustan por inflación según la frecuencia del local, las expensas crecen con su variación histórica y los variables se calculan con la venta del local y las fórmulas de la app. A cada importe se le suman el IVA y la percepción del local, se le resta la retención y se lo ubica el día de vencimiento de su razón social, en el mes que corresponde.**

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
  AlquileresCalculo ── neto de cada concepto y mes: App cerrada · Venta real · Estimado
        │
  AlquileresPago ───── neto + IVA + percepción − retención, mes y día de pago
        │                         (LA regla: una sola)
        │
  AlquileresImputacion ── las facturas imputadas reemplazan lo que cubren   ◄── Tango (getPendientes)
        │                  (LA regla: una sola)                                   + imputaciones
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
| 53 | `sql/cashflow_alquileres_facturas.sql` | `central` | El vencimiento a considerar por cuota y la imputación (cabecera, facturas, renglones, conceptos, locales), con auditoría e historial | Las facturas de alquiler **siguen en Cuentas a Pagar Locales** (condición de seguridad, parte 12) y las pantallas lo avisan en crítico; no se imputa ni se cambia un vencimiento |
| 54 | `sql/cashflow_prov_exclusion_modulos_ampliar.sql` | `central` | Amplía el `CHECK` de la exclusión por módulo a `TARJETAS_CORP` y `ALQUILERES` | Excluir de Tarjetas o de Alquileres falla con un mensaje que nombra el script |
| 55 | `sql/cashflow_alquileres_fila.sql` | `central` | Reemplaza la fila *Alquileres* (`ALQUILERES/PAGOS`) por dos filas agrupadas, `REAL` y `PROYECTADO`, en una transacción, con el control de doble conteo al pie | La fila de antes sigue activa y muestra el total, sin abrir |

El 51 va después de `sql/cashflow_prov_locales.sql` (el maestro de proveedores): sin el maestro crea las tablas igual pero no siembra a nadie, y lo dice. El 52 es independiente. **53 → 54 → 55**: el 53 después del 51, el 54 después de `sql/cashflow_prov_exclusion_modulo.sql`, el 55 después del 53 y de `sql/cashflow_estructura_grupos.sql`.

**El tablero se mueve con el código, no con los scripts**: con el módulo disponible, la fila de antes (activa, `ALQUILERES/PAGOS`) muestra el total. Ver parte 15.

### Después de correrlos

1. Dar `cashflow.tab.alquileres` y, a quien corresponda, `cashflow.editar.parametros.alquileres` (Parámetros › Alquileres) y `cashflow.editar.alquileres` (Facturas: vencimiento e imputación, y la columna *ALQUILERES* del Maestro de Proveedores Locales) desde Gestión de Usuarios.
2. En *Parámetros › Alquileres*: cargar el **día de vencimiento general**, dar de alta las demás **razones sociales**, completar los **locales** (el filtro *Incompletos* muestra los que faltan) y cargar los **pagos extraordinarios**.

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
- **Quedan fuera del cashflow** 4 (renovación de contrato / llave), 5 (comisiones) y 18 (FPC lanzamiento). Son de *Llaves y Renovaciones*.

### El concepto 9 no es un importe fijo

La ayuda de la app (`components/modalAyuda.php`) dice que el 9 es *"un importe fijo configurado previamente"*. **El código dice otra cosa y manda el código**: `totalizar()` en `js/cargaAlquileres.js` lo recalcula como `VMA × %9 / 100` cada vez que se abre el período. En los períodos cerrados de agosto y septiembre de 2026 coincide al peso en los 17 locales que tienen %9.

---

## 2. La app de alquileres: qué se lee y qué no

`Class/AlquileresApp.php`. Cada método lee una tabla y devuelve mapas por local y mes; los cruces se hacen en PHP.

### Un período abierto nunca se lee

Con el período **abierto**, la base guarda los conceptos 6 y 7 **sin restar el VMA** (y el 16 y el 17 sin restar el 9): la resta la hace el JS de la app al mostrar (`totalizar()`), así que lo guardado no es lo que se ve. Con el período **cerrado** —tiene fila en `RO_T_DETALLE_ALQUILERES_ESTADO`— lo guardado es el valor final. Verificado contra la base en 9-2026 y 8-2026: 6, 7, 16 y 17 coinciden con `max(0, venta × % − VMA o 9)`, con diferencias de $1 porque la app trunca a pesos enteros (`parseInt`).

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

Por local de la app: **razón social**, **frecuencia** (mensual, bimestral, trimestral, semestral), **mes del último ajuste** (obligatorio salvo mensual), **% IVA** (arranca sugiriendo la alícuota de Generales y se puede subir o bajar: hay locales con otros cargos), **% percepción** y **% retención** (obligatorios; 0 es un valor, vacío no), **fecha de fin** (opcional) y **venta mensual estimada** (neta; sólo para locales nuevos).

- El **mes del último ajuste es un mes del año**, no un año-mes: ancla el ciclo, y como las frecuencias dividen a 12 el dato no envejece. Trimestral con septiembre ajusta en diciembre, marzo, junio y septiembre.
- Se guarda **por fila**, como Parámetros › Tarjetas. Un trimestral sin mes del último ajuste **no se guarda** y la pantalla dice por qué.
- El filtro **Incompletos** muestra los locales que no proyectan, o no proyectan todo, por falta de algún dato.

| Estado | Cuándo | ¿Es incompleto? |
| --- | --- | --- |
| *Proyecta* | Tiene razón social dada de alta, frecuencia (y mes, si no es mensual), IVA, percepción, retención y un día de vencimiento | No. Un **local nuevo sin venta manual** proyecta fijos y expensas, pero no variables: figura en *Incompletos* con eso como faltante |
| *Incompleto* | Le falta algo de lo anterior | Sí |
| *Cerrado en Lakers* | `HABILITADO = 0` en `SUCURSALES_LAKERS` | **No**: no falta ningún dato, el local cerró. Se lista aparte y su venta real se sigue viendo. Flores 1 es este caso |
| *Contrato finalizado* | Su fecha de fin es de un mes anterior al actual | No. La fecha de fin es para los locales habilitados que dejan de pagar alquiler |

### 3.d Pagos extraordinarios

Un % sobre uno o varios **conceptos base** (de los que se proyectan) de un **mes base** del año, que se paga en *mes base + diferimiento* y **se repite todos los años** hasta que se da de baja. Por razón social, con *todos los locales* (incluidos los que se le asignen después) o algunos. No se editan: baja y alta, para que el historial explique los meses ya proyectados. Lleva IVA, percepción y retención del local.

Ejemplo: Cencosud, 75 % del VMA (8) de diciembre, 1 mes de diferimiento: se paga en enero. Los conceptos base salen de la misma jerarquía de la parte 8: si diciembre está cerrado en la app, el VMA cerrado; si no, el proyectado.

---

## 4. Fijos: el ajuste por inflación

`AlquileresCalculo::fijos()`. El mismo criterio que el valor hora de Logística (`README-logistica-local.md`, sección 4):

- Base: el importe del **último período cerrado** del local.
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
| **Manual** | Un local **nuevo** (sin 12 meses de venta en esa ventana) no entra al reparto: usa la venta mensual estimada de Parámetros. Sin ella no proyecta variables y se avisa |

- **La venta del canal es la del mes completo**, `Ventas::ventaMensualCanal()`: la base mensual (`baseMensual()`) por la participación mensual del canal. No es la grilla diaria de Ventas, que en el mes en curso arranca hoy y en los primeros 28 días usa la participación del tramo: los porcentajes del alquiler son sobre el mes entero. Al 10/11/2026, noviembre da **$ 1.652,6 M** contra los $ 1.269,6 M que muestra Ventas desde hoy, y los 12 meses suman **$ 22.372 M**. No se recalcula: se le pide a Ventas.
- **Las manuales se restan del canal antes de repartir**, así que *estimadas + manuales = canal* al centavo. El redondeo sobrante va al local con más participación. Lo fija una prueba, y el pie de la sub-pestaña Ventas muestra la diferencia, que da 0 en los meses estimados.
- **Un local cerrado no entra al denominador** (cerrado en Lakers, o con fecha de fin pasada). Un local *incompleto* pero abierto sí reparte: sigue vendiendo.
- Para los variables diferidos se calculan también los meses de venta **anteriores al horizonte** que se pagan adentro: con diferimiento 2, la venta de dos meses atrás. Esos meses tienen venta real; si a alguno le faltara, no hay venta del canal con qué estimarlo y se avisa.

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

Por local y por mes, de mayor a menor (`AlquileresCalculo::ORDEN_ORIGEN`):

1. **Factura** — la factura real imputada. **No se resuelve en `conceptosDelMes()`**: una imputación reemplaza por **mes de pago** y con el importe final, y ese cálculo es por mes del concepto y en neto. La aplica `AlquileresImputacion` sobre la lista de pagos (parte 13).
2. **App cerrada** — el período está cerrado: sus importes por concepto, tal cual, incluidos los variables.
3. **Venta real** — no está cerrado pero tiene venta: los conceptos los calcula el cashflow con esa venta.
4. **Estimado** — todo estimado.

---

## 9. Cuánto se paga y cuándo

`Class/AlquileresPago.php` es **la** regla, y la usan la pestaña y el proveedor del tablero.

```
neto        = importe del concepto (o del extraordinario)
IVA         = neto × % IVA del local
percepción  = neto × % percepción del local
retención   = neto × % retención del local      ← sobre el NETO
a pagar     = neto + IVA + percepción − retención
```

- **Mes de pago**: fijos, fondo y expensas, el mismo mes; variables, el mes de la venta + el diferimiento de la razón social; extraordinarios, el mes base + su diferimiento.
- **Día**: el de la razón social, o el general. Si no es hábil, el siguiente hábil (`DiasHabiles::siguiente()`) y la celda lo anota con `detalle`.
- **Un vencimiento que ya pasó en el mes en curso** —todavía sin factura— va al **siguiente hábil desde hoy**, nunca apilado en el primer día del eje. Es provisorio: una factura imputada que cubre ese mes lo reemplaza, en su propia fecha.
- **Fecha de fin**: desde el mes siguiente no se proyectan conceptos del local. Un variable de un mes anterior a la fecha de fin que se paga diferido después sí se paga: la venta existió.
- Cada parte se redondea a centavos una sola vez. La app trunca a pesos; como los meses cerrados se leen tal cual, eso no cambia nada de lo cerrado.

---

## 10. La pestaña

`Class/Alquileres.php` (arma, sin reglas propias), `Controller/AlquileresController.php`, `Tabs/alquileres.php`, `Js/Alquileres.js`. Las dos sub-pestañas salen de **un** pedido (`getProyeccion`).

### Proyección

- Un renglón por local con el **a pagar** de cada mes de pago (los meses del horizonte). Se abre en **Fijos, Fondo de promoción, Expensas, Variables y Extraordinarios**, y cada grupo en sus conceptos, con el **neto**. Un concepto fijo que vale 0 en todo el horizonte no se muestra (no cambia ningún total); un variable en 0 sí, porque dice que la venta no supera el VMA.
- El tooltip de cada celda: neto, IVA, percepción, retención, a pagar, día de pago (y si se corrió), origen y, según el grupo, la venta usada con su origen, el % y el mes de la venta; el % de ajuste y su ventana; la tasa de las expensas; la regla del 9; la base del extraordinario.
- El mes es el **mes de pago**: un variable diferido aparece donde se paga y el tooltip dice de qué mes es la venta.
- Columnas fijas (local y razón social), totales arriba del eje, orden por encabezado (los grupos y conceptos viajan pegados a su local con `data-orden-sigue`) y **Exportar con todo abierto**: `tabla-export.js` baja lo que se ve, así que el botón abre todo, exporta y vuelve.
- Tarjetas: a pagar en el horizonte, mes en curso, locales que proyectan y locales incompletos, con el link a Parámetros › Alquileres con el filtro *Incompletos* puesto.
- Avisos: locales incompletos, sin período cerrado, sin porcentajes, sin venta, inflación faltante, razones sociales que perdieron el rubro, calendario faltante.

### Ventas

Neta o bruta por local y mes con su origen (chip *Real*, *Estimada*, *Manual*), incluidos los meses anteriores al horizonte que pagan los diferidos. Al pie, estimadas + manuales, la venta del canal Locales y la diferencia (0 en los meses estimados). El tooltip de una estimada dice la participación del local y la venta del canal; la sub-pestaña, el período de la participación.

### Facturas

Ver parte 12.

---

## 11. Las facturas reales y el tablero

Lo que la parte A dejó pendiente llegó con `feature/alquileres-facturas-exclusion`: las partes 12 a 15.

---

## 12. Las facturas: cuáles son y de dónde salen

`Class/AlquileresFacturas.php` (la regla, pura) y `Class/AlquileresUniverso.php` (lee su contexto, una vez por pedido).

**Una factura es de alquiler si su proveedor está dado de alta (vigente) en Parámetros › Alquileres › Razones sociales.** El rubro `ALQUILER` del maestro no decide nada. `AlquileresFacturas::destino()` dice a dónde va cada fila de `Proveedores::getPendientes()`: a Alquileres, a Proveedores Locales, o a ningún lado (excluida de Alquileres). La usan Proveedores Locales (grilla, tarjetas, exportar, planilla de pagos y su proveedor), Tarjetas, la pestaña y el tablero; una prueba fija que digan lo mismo.

**Una sola consulta a Tango**: el universo sale de `getPendientes()`, que ahora trae también el neto gravado y el total del comprobante (`CPA04.SUBT_GRAVA`, `IMPORTE_TO`) para sugerir la retención. El neto de una cuota es su parte del neto del comprobante (`netoDeCuota()`); sin dato, `null`.

### La condición de seguridad

Las facturas salen de Proveedores Locales **sólo si** el módulo `ALQUILERES` está disponible, hay una **fila activa del tablero con `ALQUILERES` / `REAL` o `PAGOS`** y existen las tablas del script 53. Si falta cualquiera, se quedan en Proveedores Locales **para todos** —tampoco la pestaña Alquileres las toma— y un aviso **crítico** dice qué falta. Sacarlas antes las haría desaparecer del cuadro.

### La grilla

Una fila por **vencimiento** (cuota): razón social, comprobante, emisión, vencimiento de Tango, **vencimiento a considerar**, importe, pendiente, retención, **a pagar** y estado (*Sin imputar*, *Imputada*, *Imputada parcialmente* —otra cuota del comprobante sin imputar—, *Pagada*, *Ya no está en Tango*, *Sin dato de Tango*, *Imputación suspendida*). Filtros por razón social y estado, *Ver pagadas*, totales, exportar y tres tarjetas: **sin imputar** (cantidad e importe: plata que no suma en ningún lado), imputadas a pagar y pagos proyectados reemplazados.

### El vencimiento a considerar

**Es la única fecha de pago** de una factura de alquiler: la planilla de pagos de Proveedores Locales deja estas facturas en error. Mismo patrón que Tarjetas (`AlquileresFacturaVto`, tabla propia): input que guarda al cambiar, fecha mínima hoy (`TarjetasCorporativas::validarVtoEditado()`, la misma regla), **↺ que vuelve al de Tango**, por cuota, con historial y motivo opcional.

**Vencida**: una imputada con vencimiento a considerar anterior a hoy se paga el **siguiente día hábil desde hoy** (`AlquileresImputacion::fechaDePago()`), y la celda lo anota con `detalle`.

---

## 13. La imputación

`Class/AlquileresImputacion.php` es **la** regla de qué reemplaza una imputación, pura; `Class/AlquileresImputaciones.php` lee y escribe.

### Qué se imputa

Una o varias cuotas, **de una o varias razones sociales**, repartidas en **renglones** de **conceptos** (los que se proyectan, *Extraordinario* y *Otros (no proyectados)*, con atajos por grupo) × **locales** (uno, varios, o *todos los de una razón social* de las facturas tildadas) × **mes que cubre** (el mes de pago cuya proyección reemplaza) × **importe** (con IVA y percepciones). Los renglones son **del grupo**, no de una factura.

- **La suma de los renglones tiene que dar el pendiente de las cuotas tildadas (±$ 1).** Si no, no se guarda y se muestra la diferencia.
- **Retención por factura**, obligatoria al guardar (0 es un valor). Se sugiere con el % de retención de los locales, ponderado por el reparto, sobre el neto de Tango; sin él, sobre el importe sin el IVA ni la percepción de los locales.
- **Otros (no proyectados)** va solo en su renglón.
- **Se imputa una sola vez**: reimputar da de baja la anterior (con `ID_REEMPLAZO`) y sus otras facturas vuelven a *Sin imputar*. **Deshacer** devuelve las facturas a *Sin imputar* y la proyección vuelve. Sin bajas físicas.
- **Guardar revalida contra los pendientes de ahora** (`Alquileres::contextoImputacion()`): si una factura cambió de pendiente o ya no está, frena. La previsualización es la misma validación.

### Qué reemplaza y cuándo se paga

- Cada renglón saca de la proyección **sus conceptos, en sus locales, del mes de pago que cubre**. **Manda lo real**: no queda saldo. **Otros** suma y no reemplaza nada. **Lo no imputado sigue proyectado.**
- Cada cuota se paga **en su vencimiento a considerar** por **pendiente − retención**. La proyección reemplazada deja de pagarse en su fecha.
- En la grilla de Proyección cada renglón es **una línea dentro del grupo de sus conceptos** (o en *Facturas de varios grupos* / *Otros*), con origen **Factura**; el tooltip dice qué factura, qué renglón y la parte del local. Un renglón con varios locales se reparte **en proporción a lo proyectado** de esos conceptos en ese mes; si da 0, **en partes iguales**, con aviso. La retención, con los mismos pesos. Los centavos, por resto mayor: cada cuota suma exacto.

### Sin imputar, pagada, cambiada

- **Sin imputar no suma** —ni en la pestaña ni en el tablero—: contaría dos veces contra la proyección. Aviso de atención: *"N factura(s) de alquiler sin imputar por $ X: no suman hasta que se imputen"*.
- **Pagada en Tango**: sale de pendientes; se marca *Pagada* con la fecha de cancelación al cargar la pestaña (usuario del sistema, como la conciliación de Proveedores Locales, con `Proveedores::estadoComprobantesEnTango()`). **Sigue reemplazando** el mes que cubre. Se ve con *Ver pagadas*.
- **Desaparecida sin pago** (anulada) o sin dato de Tango: **deja de reemplazar**, la proyección vuelve y se avisa para imputar la nueva. En un grupo de varias facturas, cuando **todas** quedaron así.
- **Pendiente que cambia sin cancelarse**: se paga el pendiente nuevo menos la retención y se avisa.
- **Suspendida**: una imputación con una factura de un proveedor excluido de Alquileres o dado de baja no se lee; se avisa para deshacerla.

---

## 14. La exclusión de proveedores por módulo

`ProveedoresExclusion` suma **`TARJETAS_CORP`** y **`ALQUILERES`** a `PROV_LOCALES` (script 54). El alta en Parámetros decide si un proveedor va a Alquileres; la **exclusión** lo saca de esa pestaña y del tablero, a propósito y con motivo. **Excluido de Alquileres**: sus facturas no entran, no se imputan, no suman y **no vuelven a Proveedores Locales**; no toca la proyección de sus locales. Un aviso informativo dice cuántas y cuánto.

Se edita en la solapa **Maestro** de Proveedores Locales, una columna por módulo, **cada una con el permiso de su módulo** (`AuthCashflow::TAB_DEL_MODULO`).

---

## 15. El tablero

`ALQUILERES` disponible, tres series de **la misma lista de pagos** que agrupa la pestaña (`AlquileresProvider::armarSeries()`):

| Serie | Qué es |
| --- | --- |
| `REAL` | Las facturas imputadas, en su fecha de pago, por su a pagar |
| `PROYECTADO` | La proyección que ninguna factura reemplazó |
| `PAGOS` | Las dos juntas: la fila de antes del split. `componentes` impide que conviva con las partes |

`sql/cashflow_alquileres_fila.sql` reemplaza la fila *Alquileres* de Costos Indirectos por **dos filas agrupadas** (`GRUPO` = ALQUILERES): un renglón que se abre en real y proyectado. **La pestaña y el tablero suman lo mismo, por día**, y lo fija una prueba.

**Antes y después, al 10/11/2026.** *Cuentas a Pagar Locales* (`PAGOS`) no tenía nada de alquiler: las cuatro razones dadas de alta estaban excluidas de Proveedores Locales, en `PAGOS_EXCLUIDOS_PROVEEDOR`. Con el script 53, sus facturas pendientes (3 de Cencosud, $ 43.944.604,50) pasan a `PAGOS_ALQUILERES` y a Alquileres, **sin imputar**: no suman. Lo que mueve el Flujo Neto es la **proyección**, que entra al tablero con el código: a las 21:40 de ese día, $ 310.535.553,98 en el horizonte.

---

## Permisos

| Clave | Para qué |
| --- | --- |
| `cashflow.tab.alquileres` | Ver la pestaña (`getProyeccion` la pide) |
| `cashflow.editar.parametros.alquileres` | Todas las escrituras de Parámetros › Alquileres |
| `cashflow.editar.alquileres` | Facturas: el vencimiento a considerar, imputar y deshacer; y la columna *ALQUILERES* de la exclusión en el Maestro de Proveedores Locales |

Las tres van en `sql/cashflow_alquileres_permisos.sql` y **no** en `sql/cashflow_permisos_edicion.sql`, porque ese le da la edición a todo rol que ve la pestaña. Toda escritura pasa por `AuthCashflow::usuarioDeEscritura()`. Sin permiso de edición, los controles no se dibujan. Marcar *Pagada* lo hace el sistema (`AlquileresImputaciones::usuarioSistema()`).

---

## Pruebas

```
php tests/run.php alquileres
```

- `test_alquileres_app.php` — los períodos y el parseo de porcentajes; que la clase no tenga ni una sentencia de escritura; contra la base, que no lea meses abiertos y que en el último cerrado el 6 y el 7 ya tengan restado el VMA.
- `test_alquileres_calculo.php` — la constante de grupos; las cuatro frecuencias con inflación variable (meses de ajuste, suma sin componer, base corrida), el ajuste entre el cierre y hoy, la inflación faltante; el 9; las expensas con 24 meses, con ceros y huecos, y con 8 (tasa general); los cinco variables por encima y por debajo, el 14 sobre el 7 neto, un cierre real de Tortugas; la jerarquía.
- `test_alquileres_venta.php` — real sobre estimada, neta = bruta / 1,21, el reparto al centavo con y sin manuales, el nuevo sin manual, el cerrado fuera del denominador, la fecha de fin, la venta derivada.
- `test_alquileres_pago.php` — impuestos con retención sobre el neto e IVA de 23 % y 19 %; fin de semana, feriado, vencido en el mes en curso; diferimiento 0 y 2 con venta anterior al horizonte; día de la razón sobre el general; el extraordinario de Cencosud (todos y subconjunto, repetición anual, diciembre cerrado); fecha de fin; cada faltante que deja un local incompleto.
- `test_alquileres_parametros.php` — validadores, el universo por la columna `RUBRO`, el cableado de la sub-pestaña y su permiso, y que sin el script carga con el aviso que lo nombra.
- `test_alquileres_pestana.php` — de punta a punta con dobles: el diferido de IRSA, el extraordinario, quién no proyecta, que la pestaña sume la lista de pagos, el pie de Ventas; **REAL + PROYECTADO = PAGOS = la pestaña, por día**; la fila agrupada contra el validador.
- `test_alquileres_facturas.php` — qué factura es de alquiler (no el rubro), la condición de seguridad, las series y particiones de Proveedores Locales, la planilla en error, Tarjetas, una sola regla, el día del pase, los de rubro fuera de toda fila.
- `test_alquileres_imputacion.php` — una factura / N locales / dos razones; suma distinta; reemplazo exacto; reparto proporcional e igual; real menor y mayor; Otros; retención; reimputar y deshacer; factura que cambió; fechas y vencidas; pagada, anulada, sin dato, pendiente cambiado; el bloqueo de la baja; el cableado.
- `test_alquileres_exclusion.php` — `MODULOS` y los `CHECK`; cada columna con su permiso; Tarjetas sin el proveedor y con la exclusión por factura.
- Cableado en las existentes: `test_auth_cashflow.php` (los dos scripts de permisos, la clave reservada, 16 controllers), `test_parametros_carga.php`, `test_avisos.php`, `test_cargando.php`, `test_kpis_plegables.php`.

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
| `Class/AlquileresImputacion.php` | Puro: validar, reemplazar, repartir, fechas, pagada |
| `Class/AlquileresImputaciones.php`, `Class/AlquileresFacturaVto.php` | Leen y escriben las imputaciones y los vencimientos |
| `Js/Alquileres-Facturas.js` | La sub-pestaña Facturas y el armado de una imputación |
| `Controller/AlquileresController.php` | Los endpoints de las dos pantallas |
| `Tabs/alquileres.php`, `Js/Alquileres.js`, `Css/Alquileres.css` | La pestaña |
| `Tabs/parametros_alquileres.php`, `Js/Parametros-Alquileres.js` | La sub-pestaña de Parámetros |
| `sql/cashflow_alquileres.sql`, `sql/cashflow_alquileres_permisos.sql` | Los scripts 51 y 52 |
| `sql/cashflow_alquileres_facturas.sql`, `sql/cashflow_prov_exclusion_modulos_ampliar.sql`, `sql/cashflow_alquileres_fila.sql` | Los scripts 53, 54 y 55 |

---

## Pendientes conocidos

- **La app de alquileres filtra la venta con `LIKE`.** `traerRentabilidadBruta()` busca `PERIODO LIKE '%M-YYYY%'`, así que en **enero** `'%1-2026%'` levanta también noviembre (`'11-2026'`) y en **febrero** `'%2-2026%'` levanta diciembre (`'12-2026'`), y como recorre todas las filas se queda con la última. El cashflow compara exacto. No se toca en esta rama: es de `ProyectosXL/administracion`.
- **La ayuda de la app dice que el 9 es un importe fijo**, y el código lo calcula como `VMA × %9`. Habría que corregir la ayuda en la app.
- **Los períodos 6-2022 a 12-2022 nunca se cerraron** en la app. Quedan fuera de las ventanas del cashflow (24 meses), así que no cambian nada.
- **Mercado Libre y Sploder tienen `RUBRO = ALQUILER`** en el maestro. Aparecen en el buscador pero no están dados de alta; si alguno se diera de alta por error, sus facturas saldrían de Proveedores Locales.
- **Al 10/11/2026 hay 13 proveedores de rubro ALQUILER excluidos de Proveedores Locales y sin dar de alta acá** (más Mercado Libre y Sploder): su deuda no está en ninguna fila. Lo avisa la parte 3.b; se resuelven desde la pantalla.
- **El panel de avisos es texto**: el link al Maestro está en la lista de Parámetros › Alquileres, no adentro del aviso del tablero.
- **Rural, Flores 2 y Palmas** tienen sus fijos en 0 en el último cierre: proyectan 0.
