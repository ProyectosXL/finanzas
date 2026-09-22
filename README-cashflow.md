# Módulo Cashflow — motor de consolidación

Tablero que consolida el flujo de fondos de todos los módulos. Reemplaza a la pantalla Resumen.

Rama: `feature/cashflow-dinamico`

---

## La idea en una línea

**Ninguna fila, sección ni categoría está escrita en el código.** La estructura sale de dos tablas de configuración y cada fila declara de qué módulo saca sus datos. Agregar un módulo al tablero no toca el motor.

```
Ventas ──┐
Comex  ──┤
Cobranzas ─┤──> CashflowProvider ──> Cashflow (motor) ──> Tabs/cashflow.php
RRHH   ──┤         (contrato)          consolida            sólo dibuja
...    ──┘                             y arrastra
                                          ▲
                             CashflowEstructura (configuración)
```

Las tres capas están separadas a propósito: **configuración** (`CashflowEstructura`), **cálculo** (`Cashflow`) y **presentación** (`Tabs/cashflow.php` + `Js/Cashflow.js`).

---

## Para pasar a producción: los scripts, en orden

**Todos van contra `central`.** Todos son reejecutables y ninguno borra nada: lo que reemplazan queda inhabilitado. Si no se corren, la pantalla **no falla** — avisa.

### Scripts nuevos

| # | Script | Qué hace | Si no se corre |
| --- | --- | --- | --- |
| 1 | `sql/cashflow_cobranzas_escala_general.sql` | Crea `RO_T_CASHFLOW_COBRANZAS_ESCALA_DESC` y siembra la escala de descuento general (8/6/4/0%) | Todas las facturas proyectan con **0% de descuento** |
| 2 | `sql/cashflow_cobranzas_fecha_manual.sql` | Crea `RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL` | La fecha de cobro siempre sale del PPP y la celda editable no guarda |
| 3 | `sql/cashflow_estructura_split_cobranzas_fr.sql` | Parte la fila `COBRANZAS_FR` en `COBRANZAS_FR_REAL` + `COBRANZAS_FR_PROY` | El tablero sigue mostrando real y proyectada en un solo número |
| 4 | `sql/cashflow_dolares_comitente.sql` | Crea `RO_T_CASHFLOW_DOLARES_COMITENTE` y apunta su fila del tablero a la serie `INGRESO` | La pestaña avisa que falta la tabla y **la fila queda inválida**: apuntaría a una serie que el proveedor ya no ofrece |
| 4b | `sql/cashflow_exportaciones_tasky.sql` | Siembra `exportaciones_tasky_dias_cobro` (30) y, si no existe, la fila `EXPORTACIONES` | La pestaña proyecta con 30 días igual; la fila ya existe en una base que corrió el script 7 |
| 4c | `sql/cashflow_saldo_inversiones.sql` | Crea `RO_T_CASHFLOW_SALDO_INVERSIONES` y **crea** la fila `SALDO_INVERSIONES`, tomando la sección de la fila de dólares | La pestaña avisa que falta la tabla y la fila **no existe**, así que ese saldo no entra al tablero |
| 4d | `sql/RO_V_DOLAR_OFICIAL_BCRA_DIARIO.sql` | La cotización del dólar oficial **día por día**, sin colapsar por mes. La vista que ya existía se queda con el cierre mensual, que no sirve para valuar un saldo que está hoy en una cuenta | Dólares Cuenta Comitente avisa y **su fila va en cero**: los dólares cargados están, lo que falta es a cuánto valuarlos |
| 8 | `sql/cashflow_estructura_ingresos_egresos.sql` | Cuelga Disponibilidades y Ventas de **Ingresos**, y los tres bloques de costos de **Egresos**; agrega *Total Ingresos* y *Total Egresos*; da de baja **Ajustes** entera | El cuadro sigue plano: cinco subtotales y ninguno contesta cuánto entra ni cuánto sale en total |
| 9 | `sql/cashflow_cobertura.sql` | Amplía el `CHECK` de `TIPO` con `STOCK_COBERTURA` y `USO_COBERTURA`, crea `RO_T_CASHFLOW_COBERTURA_APLIC` y la sección **Cobertura**, y baja `SALDO_FINAL` a su final | No hay sección Cobertura: el saldo de inversiones sigue entrando al flujo como ingreso y **no se puede aplicar en ninguna fecha**. Si además se corre a medias, el editor de estructura deja elegir un tipo que la base rechaza |
| 15 | `sql/cashflow_estructura_neteo_prechequeado.sql` | Agrega la fila **Neteo cheques adelantados** a la sección Ventas, con `ORDEN = 25` (entre Franquicias y Mayoristas), apuntada a la serie `VENTAS → NETEO_PRECHEQUEADO` | **El tablero muestra la cobranza de Ventas en bruto**: las series volvieron a bruto y si la fila no existe, el neteo no se resta en ningún lado. El cuadro no falla ni avisa —cada serie es correcta por separado—, así que este es el único script del grupo cuya ausencia es *silenciosa* |
| 17 | `sql/cashflow_echeqs_excluir.sql` | Crea `RO_T_CASHFLOW_ECHEQ_EXCLUIDO`: qué cheques de **cartera** no se van a poder cobrar, con su motivo, quién, cuándo y el historial completo | **No se puede excluir ningún cheque**. La pestaña se lee igual —el listado no depende de la tabla—, los dos botones de la barra quedan apagados diciendo qué script falta, y la fila del tablero sigue trayendo toda la cartera, que es lo que traía antes |
| 18 | `sql/cashflow_saldos_cuentas_fondo.sql` | Agrega `CLASE` y el saldo inicial al catálogo de cuentas de Saldos, crea `RO_T_CASHFLOW_SALDOS_FONDO_MOV` (la cuenta corriente de cada fondo), **migra** la última foto de Otros Ingresos como saldo inicial de dos cuentas nuevas, reescribe el `ORIGEN` de las aplicaciones de cobertura a la clave de esas cuentas, y reapunta las dos filas de stock a `FONDO_INVERSION` / `FONDO_COMITENTE` | **Las filas de stock siguen leyendo de Otros Ingresos**, que está retirado: muestran la última foto cargada y el tablero avisa que ese dato ya no se mantiene. Saldos → Fondos y el ABM de fondos de Parámetros avisan qué script falta. Si además el código nuevo corre contra una base sin el script, `Cobertura::origenes()` devuelve vacío y **no se puede aplicar cobertura nueva** hasta correrlo: no hay ninguna cuenta de la que aplicar |
| 19 | `sql/cashflow_cobertura_automatica.sql` | Reapunta la fila *Uso de Inversiones* a la serie `COBERTURA → USO_INVERSION`, crea *Uso de Dólares comitente* (`USO_COMITENTE`, `ORDEN = 25`), crea *Flujo Neto Acumulado (sin cobertura)* (`SALDO_FINAL`, en Resultados debajo del flujo neto) y fija la clave **fecha + fondo** de las aplicaciones manuales con un índice único filtrado por `VIGENTE = 1` | **El motor rescata igual de las inversiones** —la fila existente sigue leyendo `APLICACION`, que nombra los fondos de lo que tenga cargado— pero **no de la comitente**: no hay fila donde mostrarlo, y el tablero avisa nombrando el script. La clave por fondo la aplica el PHP de todos modos; sin el índice, sólo el código la garantiza |
| 20 | `sql/cashflow_comex_fecha_maestra.sql` | Crea `RO_T_CASHFLOW_COMEX_FECHA_EDIT` —quién movió cada fecha de Comercio Exterior desde el cashflow— y **migra al maestro** las fechas que vivían en las columnas `EDIT` de `RO_T_CASHFLOW_COMEX_CRONO_NAC` | **Las dos pestañas de Comex se leen igual** —las fechas salen del maestro, que siempre está, y los vencidos se ven igual— pero **no se pueden editar**, y las dos avisan qué script falta. El tablero no cambia: lo que mueve sus números es el filtro de embarque que se sacó del código, no este script. En la base real la migración **no escribe ni una fecha**: las seis ediciones vigentes ya coinciden con el maestro. Ver `README-comex.md` |
| 21 | `sql/cashflow_comex_pagado.sql` | Crea `RO_T_CASHFLOW_COMEX_PAGADO`: qué pagos de Comercio Exterior ya se hicieron, con su historial. Un pago marcado **sale de la proyección** y su importe pasa a una serie propia | **El tablero no cambia**: sin la tabla no hay nada marcado, así que proyecta todo lo pendiente, que es lo que hacía antes. Las dos pestañas se leen igual, la casilla se dibuja deshabilitada y las dos avisan qué script falta. Ver `README-comex.md` |

### Scripts modificados — hay que volver a correrlos

| # | Script | Qué cambió | Si no se corre |
| --- | --- | --- | --- |
| 5 | `sql/echeqs_prechequeado.sql` | Agrega `DIAS_PRECHEQUEADO` a `RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE`, en un `ALTER` re-ejecutable | **La pestaña Echeqs falla**: el maestro se lee con esa columna |
| 6 | `sql/cashflow_estructura.sql` | La semilla nace con la cobranza FR partida, con la fila de dólares y con la fila `EXPORTACIONES` | Sólo afecta a una **instalación nueva**; una base ya sembrada no lo necesita |
| 7 | `sql/cashflow_estructura_disponibilidades.sql` | Mueve también las dos filas nuevas de FR, y `DOLARES_COMITENTE` y `EXPORTACIONES` pasan a `MERGE` | Ídem: sólo una instalación nueva. En una base que ya lo corrió, el script no hace nada |

El único **obligatorio** para que nada se rompa es el **5**. Los otros dejan la pantalla funcionando con menos, y avisando.

> **Orden dentro del grupo:** los cuatro nuevos son independientes entre sí. Los dos de estructura (6 y 7) sólo importan en una instalación desde cero, y ahí el orden es `cashflow_estructura.sql` → `cashflow_estructura_disponibilidades.sql` → `cashflow_estructura_split_cobranzas_fr.sql` → `cashflow_dolares_comitente.sql`.

### Después de correrlos, verificar

Que ninguna fila del tablero duplique importes:

- `COBRANZAS_FR` **inactiva**, y `COBRANZAS_FR_REAL` + `COBRANZAS_FR_PROY` activas. El registro declara `COBRANZA = [COBRANZA_REAL, COBRANZA_PROYECTADA]`, así que si alguien reactiva la total el validador de *Parámetros → Cashflow* lo rechaza.
- `DOLARES_COMITENTE` **una sola vez**, activa, con serie `INGRESO`.
- Ningún par (proveedor, serie) repetido entre filas activas. Eso también lo verifica el validador, y *Parámetros → Cashflow* lo muestra arriba del editor.
- `NETEO_PRECHEQUEADO` **una sola vez**, activa, con `TIPO = 'INGRESO'` y `COMPUTA = 1`. Y las filas de cobranza de Ventas —las cuatro por canal, o la total— **activas al lado de ella**: la fila del neteo corrige a esas filas, no las reemplaza.
- `ECHEQS → A_COBRAR` **activa y sola**. `A_COBRAR` ya no trae toda la cartera: trae la cobrable, sin lo excluido a mano. El universo es `A_COBRAR_TODO` y las dos mitades son `A_COBRAR` + `A_COBRAR_EXCLUIDOS`; activar el total al lado de cualquiera de las dos cuenta dos veces el mismo cheque, y eso lo rechaza el validador. **No hay que repuntar nada**: la fila ya está configurada contra `A_COBRAR`, y mientras no haya ningún cheque excluido ese código vale lo mismo que antes.
- `STOCK_INVERSIONES → FONDO_INVERSION/STOCK` y `STOCK_DOLARES_COMITENTE → FONDO_COMITENTE/STOCK`, activas. Si alguna sigue apuntando a `SALDO_INVERSIONES` o `DOLARES_COMITENTE`, el editor la marca con la advertencia de módulo retirado y el tablero avisa. El día que se corre el script 18 **el tablero no se mueve un peso**: verificado contra la base, las dos filas dan `3.529.962,37` y `1.535,00` antes y después, porque el saldo inicial migrado es exactamente la última foto que el proveedor viejo tomaba como stock. Lo que sí aparece es el aviso por fondo de la cobertura, que antes no llegaba (ver *Los fondos son las cuentas*).
- **Dos filas de uso, una por clase de fondo**: `USO_COBERTURA → COBERTURA/USO_INVERSION` y `USO_DOLARES_COMITENTE → COBERTURA/USO_COMITENTE`, activas, `COMPUTA = 1`, y **las dos entre** *Flujo Neto (sin cobertura)* y *Flujo Neto (con cobertura)*: el alcance de un flujo neto es posicional, así que una fila de uso puesta abajo del segundo no entraría en él. Ninguna fila activa con la serie `APLICACION` al lado de esas dos: es el total y el validador lo rechaza. El día que se corre el script 19 el tablero **no cambia ningún número salvo que ya hubiera columnas en rojo**: verificado contra la base el 19/09/2026, no había ninguna, así que el motor no rescató nada y la única aplicación manual vigente siguió en su columna.
- **Dos filas de saldo**: `SALDO_ACUM_SIN_COB` en *Resultados* justo debajo de *Flujo Neto (sin cobertura)*, y `SALDO_FINAL` al final de *Cobertura*. Si la de arriba quedara **debajo** de las filas de uso las incluiría y diría lo mismo que la del final.

---

## Ejecución de los scripts — la instalación completa

En este orden, contra `central`:

```sql
-- 1. sql/cashflow_estructura.sql
-- 2. sql/cashflow_estructura_disponibilidades.sql
-- 3. sql/cashflow_saldos.sql   (alimenta Saldo Inicial y Caja Locales)
-- 4. sql/cashflow_cob_electronicos.sql  (alimenta Cobranzas Pagos Electrónicos)
-- 5. sql/echeqs_prechequeado.sql        (venta cobrada anticipada y su neteo)
-- 6. sql/cashflow_cobranzas_escala_general.sql     (escala de descuento de Cobranzas FR)
-- 7. sql/cashflow_cobranzas_fecha_manual.sql       (fecha de cobro manual por factura)
-- 8. sql/cashflow_estructura_split_cobranzas_fr.sql  (parte Cobranzas FR en Real + Proyectada)
-- 9. sql/cashflow_dolares_comitente.sql  (Otros Ingresos: dolares cuenta comitente)
-- 10. sql/cashflow_exportaciones_tasky.sql  (Ingresos: plazo de cobro y fila de Exportaciones Tasky)
-- 11. sql/cashflow_saldo_inversiones.sql  (Otros Ingresos: saldo de inversiones, EN PESOS)
-- 12. sql/RO_V_DOLAR_OFICIAL_BCRA_DIARIO.sql  (cotizacion diaria, para valuar los dolares)
-- 13. sql/cashflow_estructura_ingresos_egresos.sql  (Ingresos y Egresos como bloques; baja Ajustes)
-- 14. sql/cashflow_cobertura.sql  (seccion Cobertura: stock de inversiones y su aplicacion)
-- 15. sql/cashflow_estructura_neteo_prechequeado.sql  (fila del neteo de cheques adelantados)
-- 16. sql/cashflow_comex_cotiz_edit.sql  (Comex: override de cotizacion por contenedor)
-- 17. sql/cashflow_echeqs_excluir.sql  (Echeqs: excluir de cartera lo que no se va a cobrar)
-- 18. sql/cashflow_saldos_cuentas_fondo.sql  (Saldos: cuentas de inversion y comitente; retira Otros Ingresos)
-- 19. sql/cashflow_cobertura_automatica.sql  (Cobertura: una fila de uso por fondo; clave fecha + fondo)
-- 20. sql/cashflow_comex_fecha_maestra.sql  (Comex: la fecha vive en el maestro, con rastro de quien edito)
-- 21. sql/cashflow_comex_pagado.sql  (Comex: marcar un pago como ya hecho; sale de la proyeccion)
```

**El 13 y el 14 van en ese orden y al final**, porque el 14 mueve `SALDO_FINAL` al final de la sección que crea y da de baja la fila del saldo de inversiones que crearon los anteriores. Correr el 14 sin el 13 no rompe nada, pero deja el cuadro a medio reagrupar.

**El 18 va después del 3, del 14, de `sql/cashflow_cobertura_por_fondo.sql` y de `sql/cashflow_dolares_comitente_cobertura.sql`** (los dos de `README-otros-ingresos.md`): necesita el catálogo de cuentas, la tabla de aplicaciones con su columna `MONEDA`, y las dos filas de stock que reapunta. Si las tablas de Otros Ingresos no están, no crea las cuentas y lo dice: no hay nada que migrar, y los fondos se dan de alta desde Parámetros. Ver `README-saldos.md`.

**El 19 va después del 18**: reapunta la fila de uso que creó el 14 y necesita que exista la fila de stock de la comitente para que la fila de uso nueva tenga de dónde rescatar; si no está, avisa. Es el único cuya ausencia **puede cambiar un número**: sin él el motor cubre sólo con las inversiones, y una columna que la comitente habría tapado queda en rojo, con aviso.

**El 20 va después de `sql/cashflow_comex_cotiz_edit.sql`** y de ningún otro. Es el único script del módulo que **escribe sobre una tabla que no es del cashflow** —el maestro de la plataforma Comex—, así que su encabezado documenta el criterio de conflicto y el script lista al final lo que decidió no migrar en vez de resolverlo solo. Ver `README-comex.md`.

Los que alimentan pestañas puntuales están documentados en su propio README: `sql/ventas_proyeccion.sql` y compañía en `README-ventas.md`, `sql/cashflow_cobranzas_parametros.sql` y `sql/cashflow_cobranzas_may.sql` en `README-cobranzas-fr.md` y `README-cobranzas-may.md`.

El primero crea `RO_T_CASHFLOW_CONF_SECCION` y `RO_T_CASHFLOW_CONF_FILA`, siembra la estructura y agrega el parámetro `comex_tipo_cambio_usd`, **que hoy está retirado**: los pagos a proveedores del exterior se valúan con la curva de dólar futuro ROFEX, mes a mes (ver más abajo). La fila del parámetro queda en la base —este módulo no borra parámetros históricos— pero el formulario de Parámetros ya no la muestra.

El segundo la reorganiza en **Disponibilidades + Ventas por canal**, que es la forma del Excel original (ver más abajo). No borra nada: las filas que reemplaza quedan inhabilitadas y visibles en el editor.

El tercero crea las tablas del módulo Saldos, que es el que llena las filas *Saldo Inicial* y *Caja Locales*. Se puede correr en cualquier momento; sin él, esas dos filas van en cero y el tablero avisa. Ver `README-saldos.md`.

El cuarto crea las tablas del módulo Cob. Electrónicos, que llena la fila *Cobranzas Pagos Electrónicos*. Mismo criterio: sin él la fila va en cero y el tablero avisa. Los movimientos del Excel los carga aparte `sql/cashflow_cob_electronicos_migracion.sql`. Ver `README-cob-electronicos.md`.

El quinto crea el maestro de clientes que operan con **venta cobrada anticipada** —con sus **días de pre-chequeado por cliente**, en un `ALTER` re-ejecutable para las bases que ya tenían la tabla— y la vista de la que sale el neteo de cheques adelantados de Ventas. **La fila *Echeqs en cartera* no lo necesita**: sale entera de Tango y funciona igual sin él. Lo que no funciona sin él es la segunda sub-pestaña de Echeqs, que avisa y no rompe.

El sexto y el séptimo crean la **escala de descuento general** y la tabla de **fechas de cobro manuales** de Cobranzas FR. Ver `README-cobranzas-fr.md`.

El octavo da de baja lógica la fila `COBRANZAS_FR` —que traía real y proyectada sumadas en un solo número— y la reemplaza por dos filas, una por serie. No borra la fila vieja y toma la sección de la que reemplaza, para no depender de si se corrió o no el segundo script. Ver `README-cobranzas-fr.md`.

El noveno crea la tabla de carga de los **dólares de la cuenta comitente** y deja su fila del tablero apuntada a la serie del proveedor nuevo. Ver `README-otros-ingresos.md`.

El décimo siembra el plazo de cobro de **Exportaciones Tasky** y su fila del tablero si no existía. No crea ninguna tabla: las facturas salen de `GVA12`. **Se valúan todas al dólar de hoy, a propósito** —ver `README-exportaciones-tasky.md` antes de tocar eso—.

El undécimo crea la tabla del **saldo de inversiones** y su fila del tablero, que no existía. Es el mismo circuito que los dólares de la cuenta comitente pero **se carga en pesos y no se convierte**: el criterio y cómo revertirlo están escritos arriba del script. Ver `README-otros-ingresos.md`. Ojo: el script 14 después **inhabilita** esa fila y crea en su lugar la de stock de la sección Cobertura. Se deja tal cual porque un script de migración describe el paso que dio, no el estado final.

El duodécimo crea la vista **diaria** del dólar oficial. La que ya existía colapsa a una fila por mes —el cierre—, que sirve para valuar lo que se vendió en cada mes pero no para decir cuánto valen hoy unos dólares que están en una cuenta. Las dos conviven y las dos son correctas. Ver `README-otros-ingresos.md`.

El decimotercero reagrupa las secciones bajo **Ingresos** y **Egresos** y da de baja **Ajustes**. Es un cambio de datos, no de código: reparenta cinco secciones y agrega dos filas `SUBTOTAL`.

El decimocuarto crea la sección **Cobertura** —el stock de inversiones y su aplicación por fecha— y baja `SALDO_FINAL` a su final. Es el único de los dos que trae código nuevo: una tabla, un proveedor, un controller, dos tipos de fila y el editor de la grilla.

El decimoquinto agrega la fila del **neteo de cheques adelantados**. Va después del 2, que es el que crea la sección *Ventas*; si esa sección no existe el script avisa y no hace nada, para no dejar una fila huérfana. Es el único cuya ausencia **no avisa**: las series de cobranza de *Ventas* volvieron a ser brutas, así que sin la fila el tablero muestra cobranza que ya está cobrada, y cada serie por separado es correcta. Ver *El neteo de cheques adelantados es una fila, no un descuento* más abajo.

Todos son reejecutables y no pisan nada ya editado. Si no se corrieron, la pantalla **no falla**: muestra un aviso diciendo que hay que correrlos.

---

## Las tres vistas — el criterio de TODO el módulo

| Vista | Qué muestra |
| --- | --- |
| **Días** | Las columnas diarias del tramo (`horizonte_dias`) |
| **Meses** | Las columnas mensuales (`horizonte_meses`) |
| **Período completo** | Las dos ramas juntas, en orden cronológico |

**No son las tres vistas del tablero: son las de todas las pantallas con eje temporal.** El criterio vive en un solo lugar por capa:

| Capa | Archivo | Qué resuelve |
| --- | --- | --- |
| Cálculo | `Class/EjeVista.php` | Qué columnas tiene cada vista, qué total le corresponde y qué período mide |
| Cálculo | `Class/EjeVista.php` — `armarAgrupado()` | Lo mismo, pero con una fila por **grupo** en vez de una por item |
| Presentación | `Js/eje-vistas.js` | Dibuja los botones, mantiene la vista activa y entrega las columnas visibles |

Las usan **Cashflow, Ventas, Proveedores Exterior, Crono Nacionalización, Cobranzas FR, Cobranzas May, Exportaciones Tasky y las dos sub-pestañas de Echeqs**.

> En *Echeqs → Venta Cobrada Anticipada* cada cheque se ubica en la **fecha estimada de venta** —la del cheque menos los días de pre-chequeado del cliente— y no en la del cheque: es la fecha en la que ese importe netea la cobranza proyectada de Ventas, así que es donde tiene que verse. La del cheque queda como columna de referencia y los días efectivos van al lado, para que se vea de dónde sale la estimación. Ver `README-ventas.md`.
>
> Esa sub-pestaña además **sólo muestra los de venta teórica desde hoy**: los anteriores corresponden a ventas ya facturadas y cobradas, están fuera del cashflow y no hay nada que tildar. Lo dice su leyenda —una tabla que esconde filas sin decirlo se lee como que esos cheques no existen— y lo aplica `Echeqs::ventaYaCobrada()`, la misma función con la que el neteo decide qué descarta.

**Los indicadores miden exactamente las columnas que se están mirando**, y la columna Total también. Antes eran siempre del tramo diario, aunque la pantalla mostrara los meses: el número no describía nada de lo que había en pantalla.

El **Disponible Inicial** es el único que no varía: es con cuánto se arranca hoy, un hecho del presente y no del período que uno elige mirar. Por eso su tarjeta va marcada aparte.

Una trampa que la pantalla enuncia explícitamente: **la vista Meses no cubre el horizonte completo.** Las columnas mensuales acumulan sólo los días que quedan *fuera* del tramo diario, así que su total es el del tramo mensual y no el de todo. La barra debajo de los indicadores dice en cada vista qué período se está midiendo.

### Un importe va a un día O a un mes, nunca a los dos

La columna de un mes acumula **únicamente** los días de ese mes que quedaron fuera del tramo diario. Lo implementa `Horizonte::agrupar()` y es lo que hace que las tres vistas sean sumables entre sí:

```
total_horizonte = total_tramo + total_meses     (sin repetir nada)
```

### Qué había antes, y por qué esto no es cosmético

El criterio estaba escrito **cuatro veces y de tres formas**. El tablero lo tenía bien, en métodos privados del motor. Las otras tres pestañas tenían cada una su `procesar*PorPeriodo()` copiado y pegado, con tres defectos que no se veían:

1. **La vista de días mostraba los días del mes en curso**, los ya pasados incluidos, mientras el encabezado decía *"Próximos 28 Días"*. El título no describía la tabla.
2. **Un importe del mes en curso se contaba en la vista de días Y en la columna de su mes.** Las dos vistas no reconciliaban entre sí.
3. **La ventana era fija** —el mes actual y doce meses— e **ignoraba `horizonte_dias` y `horizonte_meses`**, que son parámetros editables. Y lo que caía afuera **se descartaba sin avisar**.

Ahora los importes por columna los resuelve el backend una sola vez, y lo que queda fuera del horizonte o sin fecha se informa arriba de la tabla.

### Para agregar una pestaña con eje temporal

Backend, en el controller:

```php
$payload = EjeVista::armar(
    Horizonte::desdeParametros(new Parametros()),
    $items,            // los registros crudos
    'FECHA_PAGO',      // campo con la fecha
    'IMPORTE'          // campo con el importe
);
```

Front, en el JS de la pestaña:

```js
var vistas = crearEjeVistas({
    botones: 'misBotones',      // id del contenedor de los botones
    periodo: 'miPeriodo',       // id del cartel que dice qué se está midiendo
    alCambiar: dibujarTabla
});

vistas.usar(payload);           // cada vez que llegan datos
vistas.columnas()               // las columnas visibles
vistas.rotulo(col)              // '6/9' o 'Oct-26'
vistas.valor(fila, col)         // el importe de esa fila en esa columna
vistas.total(fila)              // el total que corresponde a la vista activa
```

No hay que calcular fechas en el navegador ni decidir qué columnas van en cada vista: eso ya está resuelto y probado.

#### Cuando la fila no es un item, sino un grupo

`armar()` resuelve **una** fecha por fila. Un resumen por cliente necesita otra cosa: una fila con importes en **varias** columnas a la vez, porque las facturas de ese cliente se cobran en fechas distintas. Para eso está `armarAgrupado()`:

```php
$payload = EjeVista::armarAgrupado(
    $h, $items,
    'COD_CLI',        // por qué campo se agrupa
    'Cobro',          // la fecha de cada item
    'importe_neto',   // el importe que va al eje; siempre se suma
    1,                // factor
    ['importe_bruto'] // otros campos numéricos a sumar
);
```

Devuelve **el mismo payload** que `armar()` —eje, vistas, totales y descartes—, así que el front es idéntico. Sin esto, un resumen tiene dos salidas y las dos son malas: agrupar por cliente + fecha (y entonces un cliente con cobros en tres fechas ocupa tres filas), o agrupar sólo por cliente y quedarse con una sola fecha, tirando la ubicación temporal del resto de la plata.

Los campos descriptivos que **difieren** dentro del grupo se descartan en vez de tomar el del primer item: una fila que dijera "FAC 0001-123" cuando en realidad son doce comprobantes es peor que una celda vacía. Lo usan los Resumen de **Cobranzas FR** y **Cobranzas May**.

> **El tablero dibuja una columna más que las demás pantallas**, y es a propósito: las columnas que no representan ningún día futuro se muestran con un guión sobre fondo gris, porque sus filas de arrastre tienen que poder decir *"acá no hay posición que mostrar"*. Las pestañas de detalle no tienen filas de arrastre y no las necesitan. Del componente compartido toman igual el estado de la vista, el rótulo del período y el total.

---

## Columnas fijas: qué se queda quieto al scrollear a lo ancho

Con 28 columnas de días a la derecha, al scrollear se pierde de vista **de quién es** cada número. La solución existía, pero estaba cableada: la primera columna por `:first-child` y una segunda por la clase `.col-medio`, con una única variable `--col-fija-1-width`. Alcanzaba para la tabla de cobranza de Ventas y para nada más.

Ahora hay tres piezas, una por capa:

| Capa | Archivo | Qué resuelve |
| --- | --- | --- |
| Estilo | `Css/main.css`, `.col-fija-1` … `.col-fija-4` | El `sticky`, el fondo opaco y los tres `z-index` de las intersecciones |
| Medición | `Js/main.js`, `medirColumnasFijas()` | Publica `--col-fija-N-left`, acumulando los anchos **reales** de las anteriores |
| Decisión | `Js/columnas-fijas.js` | Quién lleva cada clase, y el desplegable para elegirlo |

```js
crearColumnasFijas({
    tabla: 'tablaCobranzasFR',   // id de la <table>
    control: 'colFijasCob',      // id del contenedor del desplegable
    clave: 'cobranzas_fr',       // clave de localStorage
    porDefecto: [0, 1, 2]
});
```

Tres decisiones que importan:

- **Las columnas elegibles se derivan del encabezado, no se declaran.** Son la corrida de celdas con `rowspan` y `colspan="1"` que está antes del grupo del eje temporal. Una lista declarada por pestaña se desactualiza en silencio cuando alguien agrega una columna, y el síntoma sería una columna fija corrida un lugar. El corte en la primera celda de grupo es lo que deja afuera la columna *Total*, que también tiene `rowspan` pero vive al final.
- **El desplazamiento se mide, no se escribe.** El ancho lo reparte el navegador según el contenido: una razón social más larga de lo previsto desalinea cualquier valor puesto en el CSS.
- **En el pie, una celda que se pasa del bloque fijo no se fija.** Donde el rótulo *TOTALES* es una sola celda con `colspan` sobre todas las descriptivas, anclarla a la izquierda estacionaría una banda de ese ancho encima de los importes. En Cobranzas FR y May el pie pasó a tener **una celda por columna** —el `colspan` estaba escrito en duro y se desactualizaba solo—, así que el rótulo va en la primera columna fija y queda a la vista.

La elección se guarda en `localStorage` con una clave por pestaña. Es una preferencia de cómo mirar la tabla, no un filtro de datos: si se perdiera en cada recarga, no serviría para trabajar. Si el índice guardado ya no existe en la tabla, se descarta y vuelve el default, para no fijar una columna que el usuario no eligió.

**Toda tabla `.tabla-temporal` sin control explícito conserva su primera columna fija.** Es el default automático, y existe para que el mecanismo nuevo no le saque el comportamiento a las tablas que nadie pidió cambiar.

### Una fila por fila

Las celdas de `.tabla-temporal` **no envuelven**. Sin eso pasaban dos cosas, y las dos rompían la grilla como grilla:

1. **`$ 4.186.288,48` se partía entre el signo y el número.** El espacio es un punto de corte válido para el navegador, y la columna de importes es angosta porque al lado hay veintiocho columnas de días.
2. **Una razón social larga estiraba la fila a tres o cuatro renglones**, y las celdas del eje quedaban flotando en el medio de una fila altísima.

El texto largo se recorta con **puntos suspensivos** (`.col-texto`) en lugar de envolver o de estirar la columna sin techo, y el valor completo va en el `title` de la celda: lo que se recorta se puede pedir con el mouse encima, no se pierde.

Tres detalles que no son obvios:

- **El ancho de `.col-texto` va fijo** —el mismo `min` que `max`— y no sólo como máximo. En una tabla con `table-layout: auto` un `max-width` suelto es una sugerencia que el navegador ignora en cuanto el contenido no entra, y entonces no hay contra qué recortar. Cada pestaña lo corre con `--col-texto-ancho` sobre su contenedor.
- **Las celdas con `colspan` quedan afuera del `nowrap`.** En estas tablas una celda que abarca varias columnas es siempre un *texto* y no un dato —el mensaje del listado vacío, el corte por estado del pie de Echeqs, el rótulo del grupo del eje—. Forzarles una sola línea las haría desbordar justo cuando lo que tienen para decir es largo.
- **Un subtítulo dentro de la celda sigue yendo abajo**: es un `<div>`, o sea un bloque, y `nowrap` no lo afecta. Eso es a propósito — es el código de cliente debajo del nombre en Echeqs, y el `th-sub` de las tablas de Ventas.

> **El ancho de la razón social en Cobranzas FR estaba en la columna equivocada.** La regla era `#tablaCobranzasFR td:nth-child(2)`, que era `RAZON_SOC` hasta que alguien agregó `Tipo` adelante; desde entonces le daba los 200px a `COD_CLI` y dejaba la razón social apretada. Es el mismo defecto que el selector de columnas fijas evita derivando las columnas del encabezado: **un índice de columna escrito en duro se desactualiza en silencio.** Por eso ahora es una clase y no un índice.

### Dónde está aplicado, y dónde no

| Pestaña | Fijas por defecto |
| --- | --- |
| Cobranzas FR · Cobranzas May | `COD_CLI`, `RAZON_SOC` — en Resumen y en Detalle Facturas. Eran tres con `Tipo`, que se sacó: ver `README-cobranzas-fr.md` |
| Exportaciones Tasky | `N_COMP`, `RAZON_SOCI` — el rótulo del pie va en `N_COMP`, la primera fija |
| Proveedores Exterior | `Proveedor`, `Contenedor` |
| Crono Nacionalización | `Proveedor`, `Contenedor` — la primera columna es una fecha, que no identifica nada |
| Echeqs (cartera) | `N° Cheque`, `Cliente` |
| Echeqs (venta cobrada anticipada) | el tilde y `Cliente` — el tilde es lo que hay que tener a mano mientras se scrollea |
| Ventas (Cobranza Proyectada) | `Canal`, `Medio de Pago` |
| Cashflow (el tablero) | `Concepto` — **sin selector**: es la única columna descriptiva y un desplegable de una opción es ruido |
| Saldos · Cob. Electrónicos | **No se aplicó.** No tienen eje temporal: son cinco a siete columnas que entran en pantalla y no scrollean a lo ancho. Fijar una columna ahí no resuelve nada |

Cobranzas FR, Cobranzas May y Echeqs además **no tenían header fijo**: usaban `table-wrapper table-responsive` sin `.tabla-temporal`. Ahora la llevan.

---

## Ordenar por encabezado: un control, treinta y seis tablas

Ninguna tabla del módulo se podía ordenar. Ahora se ordenan todas, con
`Js/tabla-orden.js`, que es el tercer control compartido junto a `eje-vistas.js` y
`columnas-fijas.js`.

> **Por qué no DataTables**, que está cargado y lo haría solo: toma el control del
> `<table>` entero —paginado, su propio buscador, su propio redibujo— y estas tablas ya
> tienen resuelto todo eso de otra forma. El eje temporal, las columnas fijas, el header y
> el pie fijos, el buscador propio y el filtro server-side pelearían con los cuatro.

**Click en el `<th>`: asc → desc → sin orden.** El tercer click devuelve la tabla al orden
que le dio el backend. El indicador es una **clase con un `::after`** y no un `<i>`
agregado, y eso no es una preferencia de estilo: `main.js` observa `#tabContent` por
`childList`, así que un hijo nuevo en el encabezado dispararía el observer y el par
*observer → reaplicar → pintar* no pararía nunca. Por el mismo motivo `aplicar()` **no
escribe en el DOM si las filas ya están en el orden pedido**: mover filas *sí* es un cambio
de `childList`, y esa guarda es lo que corta la cadena en la segunda pasada.

### Se ordena por el valor, no por el string

`$ 1.000.000,00` es menor que `$ 9,00` como texto. El tipo se detecta por contenido:
importes en formato es-AR (`$ 1.234,56`, `USD -1.000,00`, `8%`), fechas (`dd/mm/aaaa` y
`aaaa-mm-dd`) y **rótulos de mes** (`Sep-26`, los de `Horizonte::labelMes()`). Sin ese
último, las tablas mensuales de Ventas se ordenarían Abr, Ago, Dic, Ene.

- **El tipo lo decide la mayoría de los valores, no la unanimidad.** Un `N/A` suelto en una
  columna de fechas —que es lo que deja `getCobranzasFR()` cuando el comprobante no
  aparece en `GVA12`— la convertiría en columna de texto, y entonces las fechas se
  ordenarían por el día.
- **Lo vacío va siempre al final, en las dos direcciones.** Un vacío es ausencia de dato, y
  en la punta de la tabla ocuparía justo el lugar donde uno busca el máximo o el mínimo.
- **`data-orden` en el `<td>` es el escape** para cuando el texto no sirve: un badge que
  dice *"Vencida 03/09/2026"*, un ícono. Una celda con un `<input>` se resuelve sola, por
  su `value`.

### Qué se ordena y qué no

| | |
| --- | --- |
| Columnas ordenables, `thead` de una fila | todas |
| Columnas ordenables, `thead` de dos filas | las descriptivas (`rowspan="2"`) **más `Total`** |
| Las 28 columnas de días | **no** — se aprieta una sin querer, y el rótulo es tan chico que no hay dónde poner el indicador |
| El `tfoot` | **no se ordena nunca**: los totales van al pie |
| Las filas escondidas por el buscador o por el filtro de fecha | **siguen escondidas**: el `display` viaja con la fila |

Las columnas se **derivan del encabezado** y no se declaran por pestaña, por el mismo
motivo que en `columnas-fijas.js`: una lista declarada se desactualiza en silencio.

### Se descubren solas

`reaplicar()` habilita el orden en **toda tabla con `<thead>` e `id`** dentro de
`#tabContent`. Una lista de pestañas acá no cubriría la tabla que alguien agregue mañana, y
son treinta y seis. Por eso **las tablas que no tenían `id` ahora lo tienen**: es la clave
con la que se guarda la preferencia, y una clave por posición se mudaría de tabla en cuanto
alguien agregue una arriba.

La preferencia va en `localStorage` **por nombre de columna, no por índice**. Guardar el
índice sería repetir el error que este módulo ya pagó dos veces: si alguien agrega o saca
una columna, el índice apunta a otra cosa y la tabla abre ordenada por una columna que el
usuario no eligió. Con el nombre, una columna que ya no existe hace que la preferencia se
descarte.

### Dos excepciones, y las dos declaradas

**El tablero se ordena, pero sólo por dentro de cada sección.** Sus filas derivadas
significan lo que significan **por dónde están**: un `SUBTOTAL` cierra su sección y un
`FLUJO_NETO` suma las filas que están por encima. Si se movieran, el cuadro quedaría con la
pinta de siempre y los subtotales dejarían de corresponder a las filas que tienen arriba —
el peor error posible acá: uno que no se ve. Así que `Cashflow.js` declara sus **filas
ancla**, que quedan clavadas y hacen de borde, y lo que se ordena son las filas de
movimiento de cada bloque. Una fila con una sola celda con `colspan` queda anclada sola:
no es un dato, es un texto (el rótulo de la sección, el *"No hay facturas"* del listado
vacío).

Y hay tablas donde **el orden de las filas ES el dato**, y ésas declaran `data-orden="no"`:

| Tabla | Por qué |
| --- | --- |
| `cfeTabla`, `cfeTablaSecciones` | Se reordenan con los botones ↑ y ↓, y el servidor renumera al guardar |
| `tablaAcumulada`, `tablaBalance` | Llevan una columna de **acumulado**, que sólo se lee en orden cronológico |
| `tablaMix`, `tablaEscalaCob` | Son formularios que se guardan y validan enteros, no listados |

---

## Exportar a Excel: la misma función, una sola vez

`exportarExcel()` estaba **copiada siete veces** —Cobranzas FR, Cobranzas May,
Exportaciones Tasky, Crono Nacionalización, Proveedores Exterior, Echeqs, Ventas y el
tablero—, con diferencias entre las copias:

- una envolvía en `<html>` con `<meta charset>` y las otras no, y **sin eso Excel abre las
  razones sociales con los acentos rotos**;
- una clonaba la tabla y las demás exportaban el nodo vivo;
- **ninguna sacaba los `<input>` ni los botones**, así que la columna de fecha de cobro
  editable de Cobranzas FR llegaba a la planilla con un control de formulario en vez de una
  fecha;
- y la mitad de las pestañas con tabla no tenía botón.

Ahora es `Js/tabla-export.js`, y las siete copias son una llamada:

```js
exportarTabla('tablaCobranzasFR', 'Cobranzas_FR');
```

**Exporta lo que se ve**, que es lo único defendible: quien aprieta *Exportar* después de
buscar un cliente, filtrar por fecha de emisión y ordenar por importe espera bajar eso. Sale
del DOM vivo, así que el orden y el filtro server-side vienen puestos; lo que hay que hacer
a mano es **sacar del clon las filas y las columnas que el CSS está escondiendo** —el
buscador esconde filas, el modo Resumen esconde columnas con una regla de `nth-child`—,
porque Excel no interpreta ese CSS y en la planilla aparecería todo.

> **La visibilidad se pregunta sobre la tabla viva y no sobre el clon.** Un clon suelto no
> tiene estilo calculado, así que `getComputedStyle` sobre el clon diría que todo se ve. Se
> recorren las dos en paralelo.

El nombre del archivo es `<Pestaña>_<YYYY-MM-DD>.xls`, y si no se declara uno se usa el
**título de la página**, que sale del menú: así una pestaña nueva no exporta un archivo
llamado `undefined` ni hay que declarar el nombre en dos lugares.

### Crono Nacionalización dejó de tener el suyo, y Proveedores Exterior después

Era el último con `#btnExport` + listener + una función envoltorio de una línea que ya
llamaba a `exportarTabla()`. Ahora declara `data-exportar` como el resto y el JS de la
pestaña se quedó sin las tres piezas. No cambió lo que baja, salvo por lo que sí cambió: la
pestaña tiene **buscador**, y `TablaExport` saca del clon las filas con `display: none`, así
que *Exportar* baja lo que el buscador está dejando ver.

*Proveedores Exterior* tenía las mismas tres piezas y las perdió en
`feature/comex-fecha-maestra`, cuando ganó su buscador. Ahí el cambio dejó de ser cosmético
por el mismo motivo: sin el atributo, *Exportar* habría bajado las 76 filas mientras la
pantalla mostraba tres.

> **Quedan pestañas con el botón enganchado a mano** —Cobranzas FR, Cobranzas May, Echeqs,
> Ventas, Exportaciones Tasky y Proveedores Locales—, cada una con su `#btnExport*` y su
> listener. Ya no son copias de la función: todas terminan llamando a `exportarTabla()`. Lo
> que les queda es el cableado a mano, que es trabajo de más y una pieza más que se puede
> romper en silencio. Pasarlas a `data-exportar` es un cambio aparte.

> El buscador de las **dos pestañas de Comercio Exterior** mira **sólo Proveedor, Contenedor
> y Orden de Compra**, y no el `textContent` de la fila entera como el de Cobranzas May: la
> tabla tiene una columna por día del eje, así que buscar sobre todo daría falsos positivos
> contra los importes —tipear `2026` traería todo—. El texto buscable viaja en un
> `data-buscar` sobre el `<tr>`, armado al dibujar la fila: así el filtro no depende del
> índice de ninguna columna y queda escrito en un solo lugar cuáles son los tres campos.
>
> **Es el mismo código, no uno parecido.** *Proveedores Exterior* no tenía buscador y lo ganó
> en `feature/comex-fecha-maestra`; en vez de copiarlo, el buscador y la celda de fecha
> editable se mudaron a `Js/Comex-fechas.js`, que cargan las dos pestañas. Las dos copias de
> la celda que había **ya habían divergido** —una avisaba cuando el cambio de mes descartaba
> la cotización y la otra no tenía ni eso ni la guarda que evita el doble guardado del
> `blur`—, que es exactamente cómo nacieron las siete copias de `exportarExcel()`. Ver
> `README-comex.md`.
>
> Y **la fila de TOTALES se rehace** con lo visible. Si no, el pie diría el total de todo
> arriba de una tabla de tres filas y nada en la pantalla diría que esos dos números miden
> cosas distintas. Las tarjetas de arriba **no** se tocan: miden el cronograma completo.
> Es el mismo reparto que Cobranzas May.

### Un botón nuevo es HTML y nada más

```html
<button class="btn btn-sm btn-success" data-exportar="tablaSaldos"
        data-exportar-nombre="Saldos_Por_Cuenta">
    <i class="fas fa-file-excel me-1"></i> Exportar
</button>
```

`reaplicar()` engancha todos los botones con `data-exportar`, y la llama `main.js` con el
mismo `MutationObserver`. Así se agregó el botón a las pestañas que no lo tenían —
*Cob. Electrónicos* (sus cuatro tablas), *Dólares Cuenta Comitente* (vigentes e historial),
*Saldos* (cuentas y locales), *Echeqs → Venta Cobrada Anticipada* y las de *Parámetros*—,
**un botón por tabla, en su propia `card-header`**: son cuadros distintos y bajar "la
pestaña" no querría decir nada.

> *Venta Cobrada Anticipada* es la primera tabla exportable con **tildes**. No hizo falta
> nada nuevo: `aTexto()` reemplaza cada `<input type="checkbox">` por `Sí` o `No`, así que
> la planilla dice qué cheques netean en vez de llevar controles de formulario. El único
> resto visible es el encabezado de esa columna, que es un checkbox de *marcar todo* y por
> eso baja como `Sí`/`No` en vez de como un rótulo.

Las dos tablas con `data-orden="no"` que **sí** se exportan son `tablaMix` y
`tablaEscalaCob`: no se ordenan porque son formularios, pero bajar la configuración que
explica cada importe proyectado es justamente lo que se pide de esas pantallas.

> **`tests/test_tablas_controles.php` verifica el cableado, no la lógica.** No hay corredor
> de JavaScript en el proyecto, pero lo que se rompe en silencio de estos dos controles no
> es su lógica: es el cableado. Un `data-exportar` mal escrito deja un botón que **no hace
> nada** —no tira error, no avisa—, y desde la pantalla es indistinguible de una tabla
> vacía. La prueba chequea que cada `data-exportar` apunte a una tabla que existe en su
> misma pestaña, que toda tabla tenga `id`, que los dos componentes estén cargados en
> `index.php`, que `main.js` los reaplique en el orden correcto, y que **el tipo MIME de
> Excel siga saliendo en un solo archivo** — si reaparece en otro, alguien volvió a copiar
> la función y esa copia va a divergir como divergieron las siete anteriores.

---

## Agregar un módulo al tablero

Dos pasos de código y uno de pantalla:

1. **Escribir el proveedor** en `cashflow/Class/Providers/`, extendiendo `CashflowProvider`:

```php
class HaberesProvider extends CashflowProvider {
    protected function calcular($h) {
        $rrhh = new RRHH();

        return ['PAGOS' => $h->agrupar($rrhh->getVencimientos(), 'FECHA', 'IMPORTE')];
    }
}
```

2. **Registrarlo** en `CashflowRegistry::$providers`, con `'disponible' => true`.

3. Desde **Parámetros → Cashflow**, apuntar una fila a ese par (módulo, serie). Sin tocar código.

El motor no se modifica nunca.

### El contrato

La subclase implementa `calcular()`, que **puede lanzar con total libertad**. Quien llama usa `series()`, que es `final` y lo envuelve en `try/catch (Throwable)`.

Esa división es lo importante: hace cumplir por construcción la regla de que **un proveedor nunca puede tumbar el tablero**. El tablero consolida muchos módulos y en este código un parámetro faltante lanza (`Parametros::num`) y una conexión caída también; si eso se propagara, un solo módulo con problemas dejaría la pantalla entera en blanco. El módulo que falla rinde ceros y deja un aviso.

`series()` devuelve, por cada serie:

| Clave | Qué es |
| --- | --- |
| `dias` | `['Y-m-d' => float]`, todas las claves del eje |
| `meses` | `['Y-m' => float]`, todas las claves del eje |
| `moneda_origen` | `'ARS'` o `'USD'`, informativo |
| `tipo_cambio` | Con cuál se convirtió, si se convirtió. **Es un escalar**: cuando la serie se valúa fila por fila —con una cotización distinta por mes— sólo se informa si todas las filas usaron la misma, y si no queda en `null` |
| `fuera_horizonte` | Importe que cayó fuera del eje |
| `sin_fecha` | Importe sin fecha utilizable |
| `warnings` | Avisos propios de la serie |

**Los importes siempre se devuelven en pesos.** La conversión la hace el proveedor y no el motor: el tipo de cambio de un pago futuro es criterio de negocio del módulo que paga.

Ese día llegó para Comercio Exterior, y confirmó el diseño: **Proveedores Exterior pasó de un tipo de cambio único a la curva de dólar futuro ROFEX**, con una cotización por mes, y el motor no se enteró. Lo que sí cambió de lugar es la multiplicación: ahora vive en `Class/Comex.php`, en el mismo getter que alimenta la pestaña, porque la cotización de cada contenedor es un dato que la pantalla tiene que **mostrar** y no sólo aplicar. Con la cuenta en los dos lados, el tablero y la pestaña podrían valuar distinto el mismo contenedor. El proveedor agrupa sobre el campo ya convertido, sin multiplicador. Ver `Class/DolarFuturo.php`.

Una consecuencia del contrato: con la valuación por fila ya no hay un `tipo_cambio` único que informar, así que la marca del tablero para una fila en dólares sin cotización única dice que cada importe se convirtió con el suyo y manda el detalle a la pestaña.

`fuera_horizonte` no es opcional. Un tablero de consolidación que informa de menos en silencio es peor que uno que falla.

### Anotar una celda: `detalle`

Un proveedor puede decir que **una parte** del importe de una celda tiene algo que contar:

```php
'detalle' => [
    'DIA|2026-09-17' => ['importe' => 386814.96, 'nota' => 'De este importe…']
]
```

El tablero marca esa celda con un subrayado violeta y pone la nota en el tooltip, y suma un ícono 🤝 en el nombre de la fila con el total anotado **de la vista activa**.

El caso que lo originó: en *Cobranzas Franquicias Proyectadas*, distinguir lo que sale del **PPP** —un promedio estadístico— de lo que **Tesorería pactó con el cliente** por fuera de la app de cobranzas. Las dos cosas se ven igual en el tablero y no significan lo mismo: quien mira el número para decidir necesita saber si es una proyección o un compromiso conversado. Ver `README-cobranzas-fr.md`.

Tres decisiones:

- **No es una serie aparte.** Una serie nueva sería una fila nueva del tablero, y esa fila sumaría un importe que la fila original ya suma: doble conteo. `detalle` es metadato **sobre** el mismo importe, así que no entra en ninguna cuenta. Está probado explícitamente en `tests/test_providers.php`.
- **Las anotaciones sobre columnas que no existen en el eje se descartan.** Una anotación que no se puede ver haría creer que el importe está marcado en algún lado. Y no generan aviso: lo que cayó fuera del eje ya lo informa la serie a la que anotan, porque son las mismas filas de origen.
- **Las filas derivadas no las heredan.** Una anotación dice algo sobre el origen de un importe, y el origen de un subtotal es la suma de varias filas, no ese origen.

El ícono de la fila mide **sólo las columnas de la vista activa**, igual que la columna Total: un indicador calculado sobre todo el horizonte mientras la pantalla muestra el tramo diario no describiría lo que se está viendo.

### Módulos que todavía no existen

Van igual en el registro, con `'disponible' => false` y sin clase. Una fila que los apunte se muestra **en cero** y el tablero avisa, en vez de desaparecer del cuadro: así la pantalla tiene desde el primer día la forma completa del Excel y se ve qué falta. Cuando el módulo exista, se escribe su proveedor y se da vuelta el flag; la fila ya está configurada y se llena sola.

Hoy tienen datos reales dieciséis: **Ventas**, **Cobranzas FR**, **Cobranzas Mayoristas**, **Proveedores Exterior**, **Nacionalizaciones**, **Saldos**, **Caja Locales**, **Cuentas de inversión**, **Cuentas comitente**, **Cobranzas Electrónicas**, **Echeqs**, **Exportaciones Tasky**, **Proveedores Locales**, **Cobertura**, y los dos de Otros Ingresos —**Dólares Cuenta Comitente** y **Saldo de Inversiones**— que están **retirados**: siguen sirviendo lo que tienen cargado, pero ya no alimentan ninguna fila. Los otros están declarados y rinden cero.

### Módulos retirados

Un módulo puede dejar de ser la fuente de un dato porque otro circuito lo reemplazó. No se borra del registro —las filas que lo apunten se volverían inválidas— ni se marca `'disponible' => false`, que diría *"todavía no construido"* sobre algo que existe y funciona. Lleva `'retirado' => 'por qué, y qué lo reemplaza'` y **no declara `tab`**: el proveedor sigue sirviendo, el motor avisa en cada fila que todavía lo lea que ese dato ya no se mantiene, el validador de estructura lo marca como advertencia (no como error: bloquear el guardado impediría justamente corregirla), el editor lo muestra como *Retirado* en el desplegable y en la lista de módulos, y su fila no queda como enlace, porque la pantalla de carga se eliminó. Es lo que pasó con Otros Ingresos cuando el stock de cobertura pasó a salir de las cuentas de fondo.

> **Exportaciones Tasky valúa todas sus facturas al dólar de hoy**, y no cada una al tipo de cambio del mes en que se cobra. Se aparta a propósito de la doctrina de `Class/Cotizacion.php`, que es para series históricas: acá la deuda está fija en dólares y el cobro es futuro, y valuar a hoy es no suponer devaluación. Ver `README-exportaciones-tasky.md`.

---

## Cómo se define una fila calculada, sin lenguaje de fórmulas

No hay ninguna referencia fila a fila guardada. El alcance es **posicional**, resuelto con `(SECCION.ORDEN, FILA.ORDEN)`:

| `FILA.TIPO` | Qué suma |
| --- | --- |
| `SALDO_INICIAL` | Nada: es una fila de DATOS, muestra lo que devuelve su módulo de origen |
| `INGRESO` | Suma (+1) |
| `EGRESO` | Resta (−1) |
| `STOCK_COBERTURA` | Nada, y además **no va en ninguna columna de fecha**: es un stock, no un flujo |
| `USO_COBERTURA` | Suma (+1), como un ingreso, pero **no cuenta como ingreso en los indicadores** |
| `SUBTOTAL` | Las filas de movimiento **y de saldo inicial** de su sección y de las secciones hijas |
| `FLUJO_NETO` | Las filas de movimiento **y de saldo** que estén **por encima** |
| `SALDO_FINAL` | El **arrastre**, columna a columna, del saldo y de los movimientos que estén **por encima**: la posición hasta ahí. Puede haber más de una: una arriba de la cobertura es la posición sin cubrir |

**`FLUJO_NETO` incluye el saldo que se muestra más arriba**, y eso cambió. La definición es *Ingresos − Egresos*, y los Ingresos del cuadro arrancan en el Disponible, que incluye el saldo en bancos: en el Excel `D38 = D13 + D37`. Antes sumaba sólo los movimientos, con lo que un día con saldo inicial mostraba la variación de caja y no lo que el rótulo promete.

Es el **saldo mostrado, no el arrastre**: la apertura acumulada no entra. Ésa es exactamente la diferencia entre `FLUJO_NETO` y `SALDO_FINAL`, que no se tocó. Y por eso `SALDO_FINAL` *no* suma el saldo mostrado: ahí ya entró al arrastre como aporte, y contarlo de nuevo lo duplicaría. `Cashflow::sumarSaldoMostrado()` acepta los dos filtros —por alcance de sección, para los subtotales; por posición, para el flujo neto— y cada llamador usa el suyo.

**El `ROL` de la sección no participa del cálculo.** Quien decide cómo participa una fila es su `TIPO`; el `ROL` (`SALDO` / `MOVIMIENTO` / `DERIVADO`) quedó para agrupar y para los avisos del validador. Antes sí participaba, y eso hacía imposible una sección que contuviera a la vez el saldo en bancos y las cobranzas — que es exactamente lo que tiene el Excel.

Como no hay referencias guardadas, **no puede haber referencias colgadas ni ciclos entre filas**, y renombrar una sección no rompe ninguna fórmula.

Que `FLUJO_NETO` sume "lo que está por encima" es lo que permite configurar un resultado intermedio (por ejemplo un *Resultado Operativo* antes de los ajustes) y que dé bien, sin tocar el motor.

### Dos indicadores independientes

- `ACTIVO = 0` → la fila no aparece en el tablero. Es la baja lógica: **nunca se borra un registro.**
- `COMPUTA = 0` → la fila aparece pero queda fuera de toda suma.

`COMPUTA = 0` es lo que resuelve la fila **Ventas** del Excel: es venta, no es caja (la caja es la fila *Cobros*), y contar las dos sería duplicar. Se modela como un `INGRESO` que no computa, y no como un tipo aparte, para que conserve el signo y el formato de las filas de su sección.

**No hay columna de signo**: lo determina el `TIPO`. Una columna aparte permitiría configurar "un Ingreso que resta", que no significa nada.

---

## La estructura del Excel

### Cómo queda armado el cuadro

Las secciones **cuelgan unas de otras** por `ID_PADRE`, y un `SUBTOTAL` abarca su sección y todas sus hijas en cascada. Con eso el cuadro se lee como "esto entra / esto sale" sin ningún lenguaje de fórmulas:

```
ORDEN  SECCIÓN              ID_PADRE     qué contiene
 10    Disponibilidades     INGRESOS     saldo en bancos + todas las cobranzas del día
 30    Ventas               INGRESOS     cobranza sobre ventas estimadas, por canal
 35    Ingresos             —            Total Ingresos
 50    Costo de Mercadería  EGRESOS
 60    Costos Directos      EGRESOS
 70    Costos Indirectos    EGRESOS
 75    Egresos              —            Total Egresos
 90    Resultados           —            Flujo Neto (sin cobertura)
 95    Cobertura            —            Stock y uso de cada fondo (inversiones, comitente) /
                                         Flujo Neto (con cobertura) / Saldo Final
```

**La sección madre va con un `ORDEN` mayor que el de sus hijas, y es a propósito.** El árbol define el **alcance** del subtotal; el `ORDEN` define **dónde se dibuja**. Un *Total Ingresos* tiene que caer abajo del bloque que totaliza. Las dos cosas son independientes y tienen que serlo: si el orden mandara sobre el alcance, no se podría poner un total abajo de lo que suma.

**No hay doble conteo con los subtotales anidados.** `SUB_DISPONIBLE` y `SUB_INGRESOS_VENTA` quedan dentro del alcance de `SUB_INGRESOS`, pero un `SUBTOTAL` suma filas de movimiento y de saldo, y un subtotal no es ninguna de las dos cosas. Lo fija `tests/test_cobertura.php`, sobre un escenario con dos niveles de anidación.

**La sección Ajustes se dio de baja entera** (`ACTIVO = 0`, nunca `DELETE`), junto con sus filas `FINANCIERO`, `OTROS` y `SUB_AJUSTES`. Es una decisión provisoria —más adelante se evalúa—, y por eso importa que reactivarlas sea poner el bit en 1 desde Parámetros y no volver a cargar la configuración.

Lo hace `sql/cashflow_estructura_ingresos_egresos.sql`, que **no toca el motor**: reparenta cinco secciones y agrega dos filas `SUBTOTAL`.

### Los dos bloques del cuadro original

**1. Disponibilidades** — el saldo en bancos **más** todo lo que entra por cobranzas (echeqs, cobranzas electrónicas, franquicias, mayoristas, dólares de la cuenta comitente, exportaciones, caja de locales). Su subtotal es la fila *Total Disponibilidades*:

```
Disponible(8/9) = SaldoInicial(8/9) + Echeqs + CobElec + CobFranq
                = 157.226.313 + 59.779.740 + 20.863.853 + 31.863.324
                = 269.733.230
```

Es decir: **el subtotal incluye la fila de saldo**. Por eso un `SUBTOTAL` suma las filas de saldo inicial de su alcance.

**2. Ventas** — la cobranza sobre ventas estimadas, **abierta por canal** (Locales, Franquicias, Mayoristas, Ecommerce). Su subtotal es *Total Ventas*. **Son los ingresos teóricos**: la cobranza estimada por canal, no la venta.

Y el arrastre cierra entre los dos bloques:

```
SaldoInicial(9/9) = TotalDisponibilidades(8/9) + TotalVentas(8/9) − egresos
                  = 269.733.230 + 70.280.833 = 340.014.063
```

### El Saldo Inicial es una fila de datos, no un cálculo

La fila *Saldo Inicial* muestra **lo que devuelve su módulo de origen (la pestaña Saldos) y nada más**. No arrastra.

El Excel lo confirma: el 1/9 tiene `Saldo Inicial = 0` justo después de un `Disponible` de 118 millones. Si fuera un arrastre, ahí habría 118 millones. Es un dato que carga Tesorería, y donde no cargaron nada, va cero.

El arrastre sigue existiendo, pero lo muestra **sólo `SALDO_FINAL`**, que es la posición proyectada. Los saldos que carga el módulo de Saldos entran a ese arrastre como aporte, así que la posición arranca del dinero real en cuanto haya una carga.

> **La decisión que estaba pendiente**: si un saldo cargado en una fecha intermedia **se suma** al arrastre o lo **reemplaza**. El motor sigue sumando, y el módulo de Saldos se acomoda a eso: devuelve el saldo **en la columna de su fecha y en cero en el resto del eje**, así que aporta una sola vez y no hay nada que reemplazar. Si algún día se cargan dos saldos de fechas distintas dentro del mismo horizonte, los dos se sumarían: ahí sí habría que decidir la semántica de reemplazo. Ver `README-saldos.md`.

> **Por qué las filas de Ventas son la cobranza y no la venta**: en el Excel siguen el calendario bancario (los fines de semana no tienen columna y el lunes concentra el acumulado), que es el comportamiento de la cobranza con corrimiento a día hábil. Si se quisiera ver la venta, se cambia el origen de cada fila desde Parámetros: el proveedor expone las dos series por canal.

### Totales y aperturas no se mezclan

`VentasProvider` expone diez series: cobranza y venta, totales y abiertas por los cuatro canales. Las cuatro por canal suman exactamente el total.

Activar a la vez la serie total y sus componentes cuenta **dos veces** el mismo importe, y la regla de origen repetido no lo ve, porque son series distintas. El registro declara la relación en `componentes` y el validador la rechaza.

---

## La sección Cobertura

El tablero proyecta el saldo día por día y en algunas columnas da negativo o queda muy justo. La plata para cubrir eso **existe** —está invertida—, y el tablero muestra cuánta hay y cuándo se usa.

Debajo del *Flujo Neto (sin cobertura)*:

| Fila | Tipo | Qué es |
| --- | --- | --- |
| Flujo Neto Acumulado (sin cobertura) | `SALDO_FINAL` | En *Resultados*, pegada al flujo neto: la posición acumulada **sin cubrir**, el rojo que dispara el rescate. Leyendo de arriba hacia abajo, es la que contesta "¿cuánto me falta?" antes de ver de dónde sale |
| Inversiones disponibles | `STOCK_COBERTURA` | Cuánto hay en las cuentas de inversión. **No va en ninguna columna de fecha**: el importe va en la columna Total, y **cuánto queda** en la celda de Concepto, que es la que no se va al scrollear |
| Dólares en cuenta comitente | `STOCK_COBERTURA` | Lo mismo, para las cuentas comitente, valuado a hoy |
| Uso de Inversiones | `USO_COBERTURA` | Cuánto se rescata de las cuentas de inversión en cada fecha. **Lo calcula el motor** y se puede pisar a mano desde el tablero |
| Uso de Dólares comitente | `USO_COBERTURA` | Lo mismo, para las comitente, en dólares enteros vendidos a la cotización del día |
| Flujo Neto (con cobertura) | `FLUJO_NETO` | El de arriba más lo aplicado en esa columna |
| Flujo Neto Acumulado (con cobertura) | `SALDO_FINAL` | La posición proyectada, ya con la cobertura. Es la que miran los indicadores y la que pinta las columnas en rojo |

**Dos filas de saldo, y `SALDO_FINAL` arrastra sólo lo que tiene por encima.** Esto **cambió**: era *apertura de la columna (con todo) + movimientos por encima*, y con una sola fila al final da lo mismo. Con una fila de saldo arriba de la cobertura, la apertura global la volvía un híbrido —los rescates de ayer sí, el de hoy no—; ahora es la misma regla posicional de `FLUJO_NETO` aplicada al arrastre, y la fila del final sigue dando exactamente el cierre global, que el invariante verifica. Los indicadores (*Saldo Final*, *Saldo Mínimo*) y las columnas en rojo usan **la última** fila de saldo: la de arriba muestra un rojo que la cobertura ya tapó.

**Una fila de uso por clase de fondo, no una sola con un origen.** Con el motor vendiendo dólares cuando las inversiones no alcanzan, hay que ver cada cosa en su fila. El proveedor `COBERTURA` sirve `USO_INVERSION` y `USO_COMITENTE`; `APLICACION` —el total de antes— queda declarada para volver atrás y relacionada en `componentes`, así que el validador no deja activar el total y una parte a la vez. Lo crea `sql/cashflow_cobertura_automatica.sql`.

### La cobertura la calcula el motor

> Esto **cambió** con `feature/cobertura-automatica`. El uso se cargaba a mano, celda por celda, y había que rehacerlo cada vez que se movía un vencimiento.

En cada carga del tablero, **al vuelo y sin persistir nada**, `CoberturaAutomatica::calcular()` recorre las columnas en orden cronológico arrastrando el saldo y, en cada una:

1. Entra el flujo de la columna sin cobertura, y **lo cargado a mano** en esa columna, entero: lo manual va primero.
2. Si el **saldo acumulado** queda abajo de cero, rescata **exactamente lo que falta** para llevarlo a cero. Lo que dispara el rescate es el acumulado, **no el flujo del día**: un día que gasta más de lo que entra pero viene con caja de sobra no necesita cobertura, y rescatar contra el flujo sacaría plata de una inversión que rinde sin necesitarla.
3. Si el saldo acumulado tiene **sobrante** y antes el motor rescató, **devuelve** al fondo (uso negativo) hasta recuperar lo rescatado, sin pasarse.

Las reglas que no son obvias:

- **Orden de consumo: primero las cuentas de `INVERSION`, después las `COMITENTE`.** Vender dólares es la última opción. Dentro de cada clase, el orden del catálogo de Saldos (`ORDEN`, `NOMBRE`), el mismo de la pestaña y los desplegables; a igual orden, la clave. Lo fija `CoberturaAutomatica::ordenDeConsumo()` y **no depende de cómo estén ordenadas las filas del cuadro**: reordenarlas desde Parámetros cambiaría en silencio qué fondo se vende primero, y eso es una decisión de negocio.
- **El tope de cada fondo es su saldo a la fecha de la columna** —saldo inicial + suscripciones − rescates hasta ahí, **previstos incluidos**— menos lo ya consumido en las columnas anteriores, a mano o por el motor. Un rescate cargado en Saldos → Fondos para la semana que viene deja de estar disponible desde ese día. **Un fondo nunca queda en negativo por el motor**: agotado, se pasa al siguiente. Lo único que puede dejarlo abajo de cero es una carga manual vieja o un rescate previsto que se coma lo ya usado; el motor lo clava en cero, no rescata de ahí, y avisa con la primera fecha en la que pasa.
- **Del comitente se venden dólares enteros, redondeando hacia arriba**, valuados a la cotización del día de la columna, punta vendedora (`Cotizacion::ultimaHasta`, la misma con la que se valúa el stock). Hacia arriba porque el objetivo es llegar a cero: con un dólar de menos la columna sigue en rojo. La devolución redondea **hacia abajo** por lo mismo: recomprar uno de más dejaría el saldo abajo de cero. El vuelto del redondeo queda en caja. Sin cotización para ese día no se vende ni se recompra: no hay con qué valuar.
- **Si con los dos fondos no alcanza, se aplica todo lo que hay** y la columna queda en rojo. No se inventa plata: el motor avisa cuánto falta y en qué columnas, la celda de uso lo dice en el `title`, y `cobertura.faltante` lo lleva por columna.
- **La devolución es LIFO**: se le devuelve primero al fondo del que se sacó último, así que se recompran los dólares antes de volver a suscribir a la inversión. Se lleva una pila de rescates **automáticos**: lo cargado a mano no se devuelve solo —es una decisión de alguien y deshacerla en silencio sería peor que dejarla—; para eso está el importe manual negativo. **Nunca se devuelve más de lo que se sacó**, que sería inventar una suscripción, y tampoco se le devuelve a un fondo que una carga manual negativa ya dejó entero.
- **Las columnas mensuales son un solo paso.** El eje no tiene resolución diaria fuera del tramo: una columna mensual acumula los días de ese mes que quedan fuera, y para el motor es una columna, medida al último día del mes. Un bache que ocurra a mitad de mes y se tape solo antes de fin de mes no se ve, porque el eje no lo ve.

`calcular()` es **una función pura**: recibe columnas, flujo, fondos con su tope y cotización por columna, y lo manual; devuelve el uso por columna y fondo, el saldo, el faltante y los sobregiros. No conoce el `Horizonte` ni la estructura. `Cashflow::resolverUsoCobertura()` arma sus entradas con lo que los proveedores dieron —`FondosProvider` manda el tope por columna en `fondos_tope`, `CoberturaProvider` lo manual por columna en `fondos_manual`, ver `CashflowProvider`— y escribe el resultado en las filas de uso **antes del arrastre**, que después lo recoge como a cualquier movimiento. Los dos arrastres —el del motor y el de la función— tienen que llegar al mismo cierre, y si no lo hacen queda un aviso: es el segundo invariante, al lado de `cierre[n] == apertura[n+1]`.

**Qué fondos participan:** los que tienen tope y **alguna fila de uso los nombra** (la serie de uso de cada clase lista en `fondos` todas sus cuentas activas). Un fondo con stock que ninguna fila aplica no se toca —no habría dónde mostrar el rescate— y el tablero avisa nombrando el script: es lo que pasa con la comitente hasta correr el 19. Una fila de uso con `COMPUTA = 0` no participa: un rescate que no entrara al saldo no cubriría nada.

**Lo manual tiene precedencia y el motor se calcula sobre el remanente.** Primero se aplican las cargas manuales de la fecha, y recién después el motor cubre lo que siga faltando. Una carga manual descuenta del tope de su fondo como cualquier rescate; una negativa lo repone. Si una devolución manual deja la columna en rojo, el motor la cubre: lo manual manda, el motor tapa.

**Se distingue lo calculado de lo cargado.** Cada celda de uso lleva su desglose en `cobertura_columnas` —manual y calculado, por fondo, en pesos y en la moneda del fondo— y el front lo pinta distinto: lo calculado en itálica azul, lo manual en negrita con un punto, el `title` con el detalle (*"El motor rescata US$ 1.658 de «Cuenta comitente» ($ 2.545.030)"*), y la celda de Concepto con los totales de la fila (*calculado $ X · a mano $ Y*), que suma el motor en `cobertura_totales`. **El front no calcula ninguno.**

### No hizo falta ninguna regla nueva en el motor

El alcance de las filas calculadas es **posicional**. *Uso de Inversiones* queda **debajo** de *Flujo Neto (sin cobertura)* y **arriba** de *Flujo Neto (con cobertura)*, así que el primero la excluye y el segundo la incluye, sin que el motor tenga que saber que la cobertura existe. Es exactamente el caso de uso del resultado intermedio que el diseño ya preveía, y por eso el *Flujo Neto (con cobertura)* es un `FLUJO_NETO` común y no un tipo nuevo:

```
Flujo Neto (con cobertura) = Flujo Neto (sin cobertura) + uso de esa columna
```

Sin acumular nada en la fórmula. La cuenta del Excel, `D47 = D38 + uso`.

**`SALDO_FINAL` se movió al final de esta sección**, por el mismo motivo: suma lo que tiene por encima, así que ahí recoge el uso. Si se hubiera quedado en Resultados mostraría la posición sin cubrir y contradiría a la fila que tiene justo arriba. Resultados queda con el Flujo Neto sin cobertura, que es lo que ese bloque contesta.

El arrastre, en cambio, usa **todos** los movimientos sin límite posicional, así que el uso entra a la posición proyectada esté donde esté puesta la fila.

### Por qué el uso es un movimiento pero no un ingreso

`USO_COBERTURA` está en `TIPOS_MOVIMIENTO` con signo `+1`: la plata **se mueve de verdad** y tiene que entrar al arrastre del saldo.

Pero **no suma en los indicadores de Ingresos ni de Egresos**. No es plata que el negocio genere ni gaste: es pasarla de una inversión a la cuenta. Contarla como ingreso haría subir el indicador por haber movido plata de bolsillo, y el de *Flujo Neto* dejaría de coincidir con la fila *Flujo Neto (sin cobertura)* del cuadro, que está a dos centímetros. El KPI la informa aparte, en `kpi.cobertura`, y el pie de la tarjeta de Flujo Neto lo dice cuando hay.

Donde **sí** aparece es en el *Saldo Final* y en el *Saldo Mínimo*, que salen del arrastre. Y es lo que se quiere: tapar el peor saldo proyectado es exactamente para lo que existe.

### El stock es un stock

`STOCK_COBERTURA` no va en ninguna columna de fecha. Ponerlo en un día diría que ese día entra plata, y además lo sumaría el Total de esa vista como si fuera flujo. El motor le vacía las columnas —el front las dibuja con un guión y un `title` que explica por qué— y el importe queda **sólo en la columna Total**, igual en las tres vistas: lo disponible no depende del tramo que se elija mirar.

Lo alimentan las series `STOCK` de `FONDO_INVERSION` y `FONDO_COMITENTE`: el **saldo a hoy** de las cuentas de inversión y comitente del catálogo de Saldos —saldo inicial + suscripciones − rescates—, calculado por `Fondos::saldoA()`. Ver `README-saldos.md`, *Pestaña 3 — Fondos*. Antes eran dos fotos cargadas en Otros Ingresos, y de cada tabla se tomaba la última: ver `README-otros-ingresos.md`, que quedó retirado.

### Cuánto queda, sin ir hasta el final de la tabla

El importe vive en la columna Total, que con veintiocho columnas queda a un scroll horizontal de distancia: ahí no lo mira nadie. Y la pregunta de quien está decidiendo dónde aplicar no es *cuánto hay* sino **cuánto queda**.

Así que la fila de stock lleva una segunda línea en su celda de **Concepto** —la columna que queda fija al scrollear a lo ancho—:

```
Inversiones disponibles  🐷
$ 2.529.962 de $ 3.529.962
```

Sin nada aplicado dice sólo `$ 3.529.962 disponibles`: *"queda X de X"* es ruido.

Va en una segunda línea y no al lado del nombre porque **el nombre sale de la configuración y puede ser largo**, y la columna tiene ancho fijo con puntos suspensivos: en la misma línea, el importe sería lo primero que se recorta.

**El motor lo calcula, el front lo dibuja.** `Cashflow::resolverCobertura()` cuelga `{stock, aplicado, disponible, hay_stock}` a las dos filas de la sección, después de los totales —el stock ya no está en ninguna columna a esa altura—. Es la regla del módulo: el front de este tablero no calcula nada.

**Se mide sobre todo el horizonte, no sobre la vista activa.** El stock es un stock: no cambia porque uno mire el tramo diario en vez del mensual. Si lo aplicado se midiera por vista, el disponible cambiaría al tocar un botón —la misma plata, dos números distintos— y una aplicación cargada en un mes de más adelante no se descontaría justo mientras se mira la vista Días, que es cuando se decide aplicar más.

**Un uso negativo suma al disponible**, porque devuelve plata a la inversión. Sale gratis: es la misma resta con el signo del dato.

**Lo aplicado es manual más calculado, y el resumen los separa** (`cobertura.manual`, `cobertura.automatico`, y lo mismo por fondo, con `automatizable` diciendo si el motor puede rescatar de ahí). Lo calculado es el neto de lo rescatado menos lo devuelto.

**Una carga manual que supere lo disponible en el fondo a esa fecha se rechaza**, con un mensaje que dice cuánto hay: *"a esa fecha hay US$ 65.900,00 disponibles (saldo del fondo US$ 66.000,00, menos lo ya aplicado a mano hasta ese día)"*. Disponible es el saldo de la cuenta a esa fecha —previstos incluidos— menos lo aplicado a mano desde ese fondo **antes** de esa fecha (la de la misma fecha se pisa, y las posteriores son problema de su día). **No se recorta en silencio**: recortar dejaría guardado un número que nadie tipeó. Antes se avisaba y se dejaba pasar; con el motor rescatando solo, una carga que supera el fondo ya no puede ser "un rescate que se va a hacer": un rescate previsto se carga en Saldos → Fondos y el motor lo ve. Los negativos pasan siempre. Lo hace `Cobertura::validarDisponible()`, pura, desde `guardar()`. Para un fondo que el motor **no** maneja —sin tope, por ejemplo una fila que siga leyendo del proveedor retirado— queda el aviso de antes: se aplica más de lo que hay, no se bloquea.

**Sin fila de stock no se inventa un disponible.** Puede estar inhabilitada, o su módulo puede no haber devuelto nada; `hay_stock` en `false` es lo que distingue "no se sabe" de "no hay plata". Contestar cero sería lo segundo cuando lo cierto es lo primero.

### Se pisa desde el tablero, no desde otra pantalla

La decisión que expresa una carga manual —cuánto aplicar y en qué día— se toma **mirando las columnas en rojo**. Un editor en otra pestaña obligaría a ir y volver comparando fechas, que es justamente el trabajo que la fila existe para evitar. Por eso el proveedor `COBERTURA` **no declara `tab`** y sus filas no quedan como enlace.

- Clic en una celda de una fila de uso → un input; Enter guarda, Escape cancela. **El importe se tipea en la moneda del fondo** —dólares en la comitente— que es como se guarda y como uno lo decide; la celda muestra los pesos. Lo que se propone es **lo manual que ya tiene esa celda para ese fondo**, no el total que se ve: el total incluye lo que calculó el motor, y eso no se edita, se recalcula.
- **Con más de una cuenta en la fila, el editor pregunta de cuál** con un desplegable; con una sola no molesta. Cada fila de uso sabe qué fondos aplica (`fondos_fila`).
- **Sólo las columnas diarias.** Una columna mensual acumula muchos días y la aplicación se guarda con una fecha: elegir una por el sistema —el día 1, por ejemplo— sería inventar un dato que nadie cargó. La celda mensual muestra el acumulado y lo dice en el `title`.
- Vaciar la celda **da de baja** la carga manual de esa fecha **y ese fondo**; no guarda un cero. Un cero no es una aplicación de cero pesos: es no tener ninguna, y la baja además deja rastro en el historial. Lo que queda en la celda es lo que calcule el motor.
- Guardar **recarga el tablero entero**: la carga cambia el flujo de esa columna, el saldo final de todas las siguientes, el saldo mínimo, las columnas que quedan en rojo, los indicadores y todo lo que el motor rescata de ahí en adelante. Rehacer eso en el navegador sería reimplementar en JS el arrastre y la cobertura automática.

### Las columnas con saldo negativo se marcan

Se marca la **columna entera**, encabezado incluido, y no sólo la celda del *Saldo Final*: con veintiocho columnas, encontrar el rojo de la última fila obliga a recorrerla número por número, y *"¿qué día me quedo corto?"* es la pregunta que se hace cualquiera que abre este tablero.

Se mira el **Saldo Final** y no el flujo de la columna: un día que gasta más de lo que entra no es un problema si se arranca con caja, y uno que no mueve nada sí lo es si viene arrastrando un rojo. Lo que hay que ver es la posición, no la variación. Y como el Saldo Final ya trae la cobertura aplicada, **al cargar una las columnas que se taparon dejan de marcarse solas**.

### La tabla

`RO_T_CASHFLOW_COBERTURA_APLIC`: fecha, importe, origen del fondo, observación, usuario e historial. **Guarda sólo lo manual**: lo que calcula el motor no se persiste.

- **Una aplicación vigente por fecha y fondo.** Esto **cambió**: la clave era la fecha sola, porque la fila del tablero era una. Con una fila de uso por fondo, el mismo día puede llevar una carga desde cada uno, y cada una se pisa y se borra por separado. `sql/cashflow_cobertura_automatica.sql` lo fija con un índice único **filtrado por `VIGENTE = 1`** —las pisadas y las dadas de baja tienen que poder repetir la clave: son el historial— y antes controla que no haya duplicados; si los hay avisa y no lo crea. `VERSIONES` en `getAplicaciones()` cuenta por fecha y fondo, y el historial de una celda se pide con `fecha` y `origen`.
- **El importe puede ser negativo**, y no lleva `CHECK` que lo impida: un negativo es sacar plata de la cuenta y volver a invertirla, que en una columna con saldo de sobra es una decisión tan real como aplicar cobertura. Lo que sí se rechaza es el cero, y un positivo mayor a lo disponible en el fondo a esa fecha (ver arriba).
- **No hay baja física.** Pisar una celda marca `VIGENTE = 0` las anteriores de esa fecha y ese fondo e inserta una nueva, en una transacción; borrar marca `VIGENTE = 0` y no inserta nada. El historial es lo único que explica por qué el saldo proyectado de ayer era otro: con un `UPDATE`, corregir un dedazo y cambiar de plan son indistinguibles después del hecho. Mismo criterio que `RO_T_CASHFLOW_SALDO_INVERSIONES`.
- El origen no es texto libre —un campo libre termina con *Alyc*, *ALYC* y *Fondo Alyc* conviviendo, y después no hay forma de sumar por origen— pero **tampoco es una lista del código**: es la clave de una cuenta de fondo del catálogo de Saldos. Ver la sección siguiente.

### Los fondos son las cuentas

> Esto **cambió** con `feature/cuentas-inversion`. Los orígenes eran una constante (`Cobertura::ORIGENES`: `INVERSIONES`, `SUSCRIPCION`, `DOLARES`), y cada fila de stock declaraba en el registro a qué fondo pertenecía (`'origen_cobertura'`).

Con cuentas de inversión y comitente que da de alta el usuario, el fondo ya no puede ser una constante del registro ni una lista fija: **cada cuenta de fondo es un fondo**. Su clave es `Fondos::claveFondo()` (`CTA_` + ID de la cuenta), es lo que guarda `RO_T_CASHFLOW_COBERTURA_APLIC.ORIGEN`, y su moneda es la de la cuenta. La migración reescribió las aplicaciones que ya estaban (`INVERSIONES` → la cuenta de inversión migrada, `DOLARES` → la comitente), todas, para que el historial siga nombrando un fondo que existe.

Cómo llega eso al motor sin que el motor conozca ninguna cuenta:

| Quién | Qué pone en la serie |
| --- | --- |
| `FondosProvider` (stock) | `por_fondo`: cuánto stock aporta cada cuenta, por clave, ya en pesos. `fondos`: el nombre de cada una |
| `CoberturaProvider` (uso) | `por_fondo`: cuánto se aplicó desde cada cuenta, misma clave |
| `Cashflow::resolverCobertura()` | Cruza por clave, calcula el disponible por fondo y avisa **por fondo** cuando se aplica de más, nombrando la cuenta |

Un stock que no reparte —una fila que siga leyendo del proveedor retirado— suma al total y a ningún fondo. Una aplicación cuyo origen no es ninguna cuenta —una clave vieja que la migración no pudo mover, por ejemplo `SUSCRIPCION`— suma al total, abre un fondo sin stock para que se vea, y `CoberturaProvider` avisa cuánto es. Está fijado en `tests/test_cobertura.php`.

`Cobertura::origenes()` lista las cuentas de fondo —incluidas las inhabilitadas, porque una aplicación vieja tiene que poder nombrar la suya— y los helpers puros (`validarOrigenEn()`, `monedaDeOrigenEn()`, `origenDefectoDe()`) reciben esa lista. **El origen por defecto es la primera cuenta de fondo activa en pesos**, por orden del catálogo: no es una cuenta escrita en el código, es una regla. El editor del tablero ya manda el origen —cada fila de uso sabe qué fondos aplica—, así que el defecto queda para un pedido que llegue sin él.

Con la cobertura automática las series de la sección llevan dos cosas más, por el mismo camino: `fondos_tope` en el stock (el saldo de cada cuenta **en cada columna**, en su moneda, con la cotización de cada columna si es en dólares) y `fondos_manual` en el uso (lo cargado a mano desde cada cuenta, por columna, en las dos monedas). Son las entradas de `CoberturaAutomatica`, no salen en el JSON del tablero, y `normalizar()` las deja pasar como a `por_fondo`.

> **Un bug de `develop` que salió con esto.** `CashflowProvider::normalizar()` arma la serie con una lista cerrada de claves, y el reparto por fondo del uso —entonces `por_origen`— no estaba en ella: `CoberturaProvider` lo colgaba, el motor lo esperaba, y en el medio se descartaba en silencio. Contra la base real `aplicado` por fondo era siempre cero y **el aviso por fondo nunca se disparó**; sólo `tests/test_cobertura.php`, que reemplaza `pedirSeries()` y se saltea `normalizar()`, lo veía funcionar. Ahora `por_fondo` y `fondos` están en el contrato, `normalizar()` las deja pasar, y hay una prueba que pasa por `series()` —el camino real— para que no vuelva a perderse. Consecuencia visible: el tablero ahora avisa *"se aplican $ 4.000.000 de «Inversiones» pero ahí hay $ 3.529.962,37"*, que era cierto desde antes.

### El saldo de inversiones dejó de ser un ingreso

Su fila en Disponibilidades quedó **inhabilitada**. Entrar al flujo como ingreso en la fecha de su carga decía que ese día ingresaba plata, y no es cierto: la plata ya está. La serie vieja `INGRESO` sigue declarada para poder volver atrás desde Parámetros, pero las dos son el mismo dinero y están relacionadas en `componentes`, así que el validador rechaza tenerlas activas a la vez.

Esto **contradice** lo que decía el encabezado de `sql/cashflow_saldo_inversiones.sql` (*"ES UN INGRESO, NO UNA DISPONIBILIDAD"*); ese texto quedó reescrito junto con el cambio, porque una nota que dice lo contrario de lo que hace el código es peor que no tener nota.

**Dólares Cuenta Comitente no se tocó:** sigue siendo un ingreso.

---

## El arrastre del saldo

Es la parte que más fácil sale mal en silencio, porque **las columnas no están en orden cronológico**.

Con `horizonte_dias = 28` y hoy = 06/09/2026:

- Las 28 columnas diarias van del 6/9 al 3/10.
- La columna del mes `2026-09` contiene **sólo del 1 al 5 de septiembre**, que ya pasaron y que ningún proveedor genera. Queda legítimamente vacía.
- La columna `2026-10` contiene del 4 al 31 de octubre, o sea **después** de la última columna diaria.

Y el orden se invierte según el horizonte: con `horizonte_dias = 20` el tramo cierra el 25/9 y el resto de septiembre cae **después** del tramo.

Por eso el arrastre recorre `Horizonte::secuencia()`, que devuelve las columnas en orden cronológico real, y no las columnas como se dibujan. Recorrerlas en el orden de la pantalla daría mal en los dos sentidos.

```
saldo = 0
para cada columna de la secuencia:
    flujo    = suma de las filas de movimiento con COMPUTA = 1
    apertura = saldo                          <- lo que muestra SALDO_INICIAL
    saldo    = saldo + aporteDeSaldo + flujo
    cierre   = saldo                          <- lo que muestra SALDO_FINAL
```

El motor verifica que `cierre[n] == apertura[n+1]`; si no da, deja un aviso y no una excepción. Ese es el arrastre **global**, con todos los movimientos; lo que muestra cada fila `SALDO_FINAL` es el mismo arrastre pero sólo con las filas que tiene **por encima**, así que la del final coincide con el cierre global y una puesta antes de la cobertura es la posición sin cubrir.

### Dos consecuencias que se ven en pantalla

- **Una columna fuera de la secuencia devuelve `null`, no cero**, y se dibuja con un guión sobre fondo gris. Un cero en *Saldo Final* se leería como "proyectamos cero pesos de caja", que sería mentira.
- **El total de una fila de saldo no es una suma.** Sumar saldos de apertura no significa nada: el total del tramo es el saldo al cierre del tramo, y el del horizonte el saldo al final de todo.

---

## El menú lateral y el estado de cada pestaña

La lista de pestañas y el estado de cada una salen de `Class/Menu.php`; `Components/sidebar.php` sólo dibuja. Antes eran veintiséis enlaces escritos a mano e iguales entre sí, y por eso no se podía ver de un vistazo qué está hecho.

**Tres estados, no dos:**

| Estado | Qué significa | Cómo se ve |
| --- | --- | --- |
| `datos` | La pestaña lee del sistema. Se puede confiar en lo que muestra | Normal, sin marca |
| `maqueta` | **Dibuja pero los números son de ejemplo** | Ícono ámbar 📐 |
| `pendiente` | Todavía no se desarrolló; muestra el aviso de *en construcción* | Atenuada, ícono 🪖 |

> **No hay un cuarto estado para una pestaña que dejó de alimentar el tablero.** Las dos de Otros Ingresos quedaron en esa situación cuando el stock de cobertura pasó a salir de las cuentas de fondo de Saldos, y **se eliminaron** junto con su categoría: una pantalla que abre, funciona y guarda, y cuyo número no va a ningún lado, confunde aunque lleve un cartel. Sus tablas quedan, por el histórico. Ver `README-otros-ingresos.md`.

**El estado del medio es el que importa, y es el que faltaba.** Hoy lo tiene el **Dashboard**: no tiene una sola llamada al servidor, así que sus números están escritos a mano. Un placeholder es honesto —dice que no está hecho—; una maqueta es peor, porque tiene la forma de una pantalla terminada y números que parecen reales. Meterla en la misma bolsa que las pestañas con datos sería el error caro que este módulo evita en todos lados.

Una pestaña con datos **no se marca**: es el caso normal y marcarlo sería ruido. Las pendientes siguen siendo clickeables, porque el aviso de *en construcción* es información útil.

### El título de la página también sale del menú

`main.js` tenía una segunda lista de nombres escrita a mano para el encabezado, y se desactualizaba sola: cada pestaña nueva aparecía arriba con su código y guión bajo (*exportaciones_tasky*, *dolares_comitente*). Ahora el enlace del menú lleva `data-encabezado` y `updateHeader()` lo lee de ahí. Por defecto es el nombre del menú; cuando ese va abreviado para entrar en el sidebar, el item declara `'encabezado'` con el nombre completo: *Cobranzas FR* → **Cobranzas Franquicias**, *Cobranzas May* → **Cobranzas Mayoristas**, *Cob. Electrónicos* → **Cobranzas Electrónicas**.

### El placeholder se detecta, no se declara

`datos` y `maqueta` son un juicio y van declarados. Pero si el archivo de la pestaña todavía incluye `Components/tab_placeholder.php`, el estado **baja** a `pendiente` sin importar lo declarado.

La guarda va en esa dirección a propósito: lo que hay que evitar es que el menú **prometa datos que no existen**. Así una declaración que quedó vieja se corrige sola, y lo peor que puede pasar es que una pestaña recién terminada siga figurando como pendiente hasta que alguien actualice la lista — un error visible y sin consecuencias.

### El contador de cada categoría

Cada categoría muestra `n/m`: cuántas de sus pestañas tienen datos del sistema. Sirve para ver el avance sin abrirla, y **cuenta sólo `datos`** —una maqueta no suma—, que es lo que hace que el número sea confiable. Hoy: Ingresos 7/7, Comercio Exterior 2/2, Proveedores 1/3, y el resto en cero.

**Otros Ingresos va después de Ingresos y aparte**: Ingresos agrupa lo que sale de un circuito del sistema y esa categoría agrupa lo que se tipea. La diferencia importa al leer un número — en una fila de Ingresos un cero es *"no hay movimientos"* y en una de esas es *"nadie cargó nada todavía"*. Ver `README-otros-ingresos.md`.

### Parámetros va al pie

No es un módulo de datos como los de arriba: es la configuración de todos ellos. Arriba competía por atención con el tablero, que es la pantalla que se abre para trabajar.

Los ítems de las categorías ahora tienen ícono propio, así que el sangrado de 44px que hacía de guía visual se reduce y el ícono ocupa ese lugar, alineándolos con las pestañas de nivel raíz.

---

## Administración desde Parámetros

Sub-pestaña **Parámetros → Cashflow**. Permite crear filas y secciones, renombrarlas, cambiarles la sección, reordenarlas, habilitarlas e inhabilitarlas.

- **El orden nunca lo manda el cliente.** Los botones ↑ y ↓ mueven la fila en la pantalla y el servidor renumera desde cero con `(índice+1)*10` en cada guardado. Así el orden se repara solo y no existen los órdenes duplicados ni los huecos.
- **El código interno lo slugifica el servidor**, sin importar lo que mande el cliente, y es **inmutable** después del alta: es la clave con la que se referencia la fila. Si quedó mal, se inhabilita y se crea otra.
- **Las filas entran inhabilitadas.** Una fila inhabilitada no puede invalidar la estructura, así que el alta no necesita validar el árbol completo y no puede romper un tablero que estaba bien.
- **No hay bajas.** Se inhabilita, y un switch decide si las inhabilitadas se ven en el editor.
- El servidor valida el **estado resultante simulado**, no lo que manda el cliente: un envío parcial no puede colar una estructura inválida. El JS espeja la validación sólo para bloquear el botón y explicar por qué.

`guardar()` abre **una** conexión y envuelve todo en una transacción. **Es la primera transacción del proyecto**, y hace falta: `Conexion::conectar()` abre una conexión nueva en cada llamada, así que las escrituras de varias filas que ya existen en el sistema confirman por separado. Para un porcentaje eso es una molestia recuperable; para un renumerado de estructura dejaría órdenes duplicados y secciones renombradas con filas huérfanas.

### Qué bloquea el guardado

Código repetido; código o nombre inválido; fila activa cuya sección no existe o quedaría inhabilitada; tipo desconocido; fila activa sin origen de datos; origen no registrado; **dos filas activas leyendo el mismo origen** (doble conteo); más de un saldo inicial activo; subtotal que no tiene nada que sumar; ciclo en la jerarquía de secciones; y la pantalla desactualizada, si alguien agregó o quitó filas mientras tanto.

Los mensajes enuncian la **consecuencia de negocio**, no la regla: *"su importe desaparecería del tablero"*, *"el importe se contaría dos veces"*.

---

## Dos clases de aviso, y no hay que mezclarlas

| | Sobre qué | Dónde vive | Cuánto dura |
| --- | --- | --- | --- |
| **Aviso** | Los **datos**: *"$ 1.200 quedaron fuera del horizonte"* | Pintado en la pantalla, arriba de la tabla | Mientras el dato siga así |
| **Notificación** | Una **acción del usuario**: se guardó, falló, falta un campo | Esquina inferior derecha, sobre todo lo demás | Se descarta |

Lo primero lo genera el backend y es parte de lo que la pantalla informa; lo segundo es la respuesta a un click. Un aviso que desaparece solo sería un dato perdido, y una notificación permanente sería ruido.

Las notificaciones las resuelve `Js/notificaciones.js` (`Notificacion.exito / error / advertencia / campoInvalido / confirmar / pedirTexto / pedirFecha`), cargado en `index.php` porque su contenedor cuelga de `<body>` y tiene que sobrevivir al reemplazo de `#tabContent`.

**Un error no se cierra solo.** Trae el mensaje del servidor, que es lo único que explica por qué el dato no quedó guardado; que se borre a los cuatro segundos es perderlo. Los éxitos sí, y el temporizador se pausa con el mouse encima.

**`Notificacion.confirmar()` devuelve una promesa**, así que reemplaza a `confirm()` pero no bloquea el hilo: lo que iba después del `if` va adentro del `then`. El foco arranca en *Cancelar* — son acciones que cuestan deshacer y un Enter reflejo tiene que no hacer nada.

**Los tres diálogos comparten un solo armazón** (`abrirDialogo()`): `confirmar()` contesta sí o no, `pedirTexto()` devuelve el texto o `null`, y `pedirFecha()` devuelve `'aaaa-mm-dd'` o `null` —nunca un `Date`: el módulo mueve fechas como string para no pasar por `new Date(string)`—. Lo delicado no es el HTML: es que cerrar con la cruz, con Escape o clickeando afuera **también sea una respuesta, y sea la negativa**. Tres copias de eso se desincronizan en la primera corrección. Cuando el diálogo tiene un campo, el foco arranca ahí y un campo obligatorio vacío **no cierra**: dice por qué en el mismo lugar donde se completa, en vez de fallar después contra el servidor.

---

## Lo que el tablero avisa, y por qué

Los avisos no son decoración: son lo que evita leer un cero como si fuera un dato.

- Módulos todavía no construidos cuyas filas rinden cero (agrupados en un solo aviso).
- Importes que cayeron **fuera del horizonte** o **sin fecha**, con el monto.
- **Comercio Exterior ya no filtra por fecha de embarque**, y con eso se fueron los dos avisos que ese filtro necesitaba. El corte escondía **42 de 76 contenedores** —verificado el 19/09/2026—, entre ellos 10 con la fecha de pago todavía por delante: eran egresos reales informados de menos, y el aviso lo decía sin poder arreglarlo, porque la fila tampoco se veía en la pestaña. Ahora lo que decide es la fecha efectiva de cada contenedor. Ver `README-comex.md`.
  - **Lo vencido no suma, en las dos series**: al cashflow entra lo que se mueve de hoy en adelante. O ya salió —y no es proyección— o hay que corregirle la fecha, y las dos cosas son gestión sobre el dato. El aviso dice cuántos son y cuánto valen, porque si no esa plata desaparece del tablero sin que nada lo explique. **No se reubica en hoy**, a diferencia de las tres pestañas de cobranza proyectada; el porqué está en `README-comex.md`.
  - En las dos pestañas las vencidas además **se esconden por defecto**, detrás de un interruptor *Ver vencidas*, con el conteo al lado. Como ya valían cero en el período, esconderlas **no cambia ningún total**. El aviso del tablero por eso **no dice dónde están en la pantalla**: el mismo texto lo muestran el tablero y la pestaña, y afirmar "están marcadas en la grilla" sería falso donde el interruptor las tiene escondidas.
  - **Lo marcado como ya pagado sale de la proyección**, y el aviso dice cuánto. Existe precisamente porque ese importe **ya no está en la fila**: sin él, un egreso que el tablero debería proyectar desaparece y nada en pantalla lo explica. El importe no se pierde — sale por `PAGOS_PAGADOS` / `NACIONALIZACION_PAGADAS`, y el invariante con el universo cierra columna por columna.
  - **Nacionalizaciones dejó de dar cero.** El aviso decía que ningún contenedor tenía gastos estimados cargados, y eso era cierto **de los 34 que el filtro dejaba pasar**: los gastos se cargan cuando el contenedor ya embarcó, así que el filtro escondía exactamente los que tenían el dato. La fila pasó de `$ 0` a `$ 561.423,77` dentro del horizonte. La guarda que avisa sigue en `ComexProvider`, por si algún día vuelve a pasar de verdad.
- **Contenedores del exterior que no se pudieron valuar**, con el conteo y **cuánto suman en dólares**. Pasa cuando no tienen fecha estimada de pago: sin fecha no hay mes, y sin mes no hay cotización que pedirle a la curva de dólar futuro. No se los convierte con ningún tipo de cambio inventado. El monto va **en dólares y no en pesos** a propósito: decirlo en pesos exigiría valuarlo, que es justamente lo que no se pudo hacer.
- **Contenedores valuados con un mes que la curva no cubre**, con el conteo y hasta dónde llega la curva. Se usa la cotización del mes más cercano y la fila queda marcada en la pestaña; aproximar en silencio sería mostrar un número que nadie puede explicar.
- **Contenedores con la cotización corregida a mano**, que manda sobre la curva.
- Falta de la curva de dólar futuro. Si no se puede leer, la fila de Proveedores Exterior va en cero con un aviso que nombra la tabla, en lugar de tumbar el tablero.
- **Exportaciones Tasky ubica en hoy las facturas cuya fecha de cobro estimada ya venció**, con el conteo y los dólares. Son facturas vencidas sin cobrar, no cobranza estimada para hoy; la celda de hoy además queda anotada con `detalle`. Sin cotización de hoy, la fila va en cero y el aviso dice cuántos dólares quedan sin valuar.
- **Cobranzas Franquicias y Mayoristas hacen lo mismo desde que la regla se unificó** (`Ingresos::ubicarCobroVencido()`, ver `README-cobranzas-fr.md`): las facturas proyectadas vencidas de hasta 180 días atrás entran en la columna de hoy, y el aviso trae el conteo y el importe. Acá va **como aviso y no como `detalle`**, a diferencia de las exportaciones: el contrato admite una anotación por celda y la celda de hoy de la cobranza proyectada ya puede tener la de la fecha pactada a mano. Dos notas por la misma celda dejarían ver una sola.

---

## Relación con Ventas

`COBROS_VENTAS` (proyectada) y `COBRANZAS_FR` (real) **no se pisan**: Ventas proyecta cobranza de ventas *futuras* y Cobranzas FR trae cobranza de facturas *ya emitidas*. Se suman a propósito.

```
Cobranza total = cobranza real (facturas emitidas) + cobranza sobre ventas estimadas
```

El invariante está enunciado en el encabezado de `Class/Ventas.php`.

### El neteo de cheques adelantados es una fila, no un descuento

Hay clientes que entregan los echeqs **antes** de que se les facture: esa venta futura ya está cobrada, así que proyectar su cobranza la contaría dos veces. Lo que hay que restar sale de *Echeqs → Venta Cobrada Anticipada*.

Hasta ahora ese neteo se restaba **adentro** de las series de cobranza del proveedor `VENTAS`. Ahora esas series son **brutas** y el neteo entra al cuadro por la fila `NETEO_PRECHEQUEADO`, sección *Ventas*, `ORDEN = 25` —entre Franquicias y Mayoristas—, alimentada por la serie `VENTAS → NETEO_PRECHEQUEADO`.

| | |
| --- | --- |
| `TIPO` | `INGRESO`, con **importe negativo**. No `EGRESO`: esto no es plata que sale, es cobranza que no va a entrar porque ya entró. Y `EGRESO` le daría signo −1 a un importe que ya viene negativo, con lo cual el neteo terminaría *sumando* |
| `COMPUTA` | `1`. La fila entra en el subtotal *Total Ventas*, que es el punto: ese subtotal tiene que dar la cobranza neta |
| El signo | Lo invierte `VentasProvider::enNegativo()`, en un solo lugar. `Ventas` devuelve el neteo en positivo —es *cuánto hay que restar*— y la serie lo devuelve en negativo |
| El alcance | La serie lleva el total de **todos los canales**. Hoy todo el neteo es de franquicias, pero eso es un hecho del padrón de clientes, no una regla: un mayorista que entregue cheques adelantados entra en la misma fila sin tocar código |

> **Es crítico que las series de cobranza sigan siendo brutas.** Netear adentro de `COBRANZA` —o de las series por canal— con esta fila activa restaría el neteo **dos veces**, y ninguna validación lo detecta: las dos series son legítimas por separado. Está escrito también en el encabezado de `Class/Providers/VentasProvider.php`.

`NETEO_PRECHEQUEADO` **no** está declarada en `componentes`: no es una apertura de `COBRANZA` sino una fila que convive con ella, y declararla ahí haría que el validador rechace la combinación normal del tablero.

La crea `sql/cashflow_estructura_neteo_prechequeado.sql`. Ver `README-ventas.md`.

### Excluir un cheque que no se va a poder cobrar

La fila *Echeqs en cartera* trae todo lo que en Tango está en estado `C` con fecha de hoy en adelante. Un cheque que ya se sabe que no entra —el cliente avisó que no lo cubre, quedó judicializado, está en gestión de cambio— sumaba igual al disponible y no había dónde decir que no.

Ahora se lo tilda en *Echeqs → Cheques en Cartera*, con un motivo. **Aplica sólo a cartera: el pre-chequeado no se toca.**

**El importe no desaparece: cambia de serie.** Es el mismo criterio que la exclusión de facturas de Proveedores Locales —ver el encabezado de `sql/cashflow_prov_locales_excluir_factura.sql`—, y acá el corte nace con esta etapa, porque `ECHEQS` tenía una sola serie y era el universo entero:

```
A_COBRAR + A_COBRAR_EXCLUIDOS = A_COBRAR_TODO
```

| Serie | Qué trae |
| --- | --- |
| `A_COBRAR` | La cartera **cobrable**. Es la que usa la fila del tablero |
| `A_COBRAR_EXCLUIDOS` | Sólo lo excluido a mano, uno por uno |
| `A_COBRAR_TODO` | El universo. Es lo que `A_COBRAR` significaba hasta ahora |

> **`A_COBRAR` cambió de significado y no de código, a propósito.** Es el que la fila del tablero ya tenía configurado, así que el circuito entró sin repuntar ninguna fila ni tocar *Parámetros*. El día que se corre el script no hay nada excluido, con lo cual **el tablero no se mueve ni un peso**: verificado contra la base, son 390 cheques por $2.056.009.561,46 y las tres series dan ese número. Lo que el tilde cambia es de qué serie sale cada importe, nunca cuánta plata hay.

**Los dos tildes de la pestaña Echeqs no son el mismo**, y no se cruzan:

| Sub-pestaña | Tilde | Qué pregunta | Dónde vive |
| --- | --- | --- | --- |
| Cheques en Cartera | **Excluir** | *esta plata, ¿va a entrar?* | `RO_T_CASHFLOW_ECHEQ_EXCLUIDO` |
| Venta Cobrada Anticipada | **Marcar** | *esta venta, ¿ya se cobró?* | `RO_T_CASHFLOW_ECHEQ_PRECHEQ` |

Un mismo cheque puede tener los dos y ninguno implica al otro: que no vaya a entrar no dice nada sobre si la venta que prepagó hay que netearla. Por eso no hay ningún join entre las dos tablas y son dos endpoints distintos.

Lo demás sigue el patrón de Proveedores Locales, por los mismos motivos:

- **El motivo es obligatorio**, y lo valida el back (`Echeqs::validarMotivoExclusion()`), no la pantalla: el endpoint es alcanzable sin pasar por la grilla.
- **Uno solo para todo el lote.** Excluir los doce cheques de un cliente que entró en concurso es *una* decisión, y doce motivos distintos son doce oportunidades de que digan cosas distintas.
- **Se eligen con checks y se confirman juntos**, en una transacción. Es el gesto del tildado masivo de la otra sub-pestaña, con una diferencia: acá el check de la fila **selecciona** y no actúa. Sacar plata del disponible no puede dispararse con un clic suelto.
- **Los excluidos se esconden por defecto**, con un interruptor *Ver excluidos* que se puede prender. Cuánto esconde se dice arriba de la tabla **siempre**, y `EcheqsProvider` deja el mismo aviso en el tablero, con los motivos: una exclusión puesta en marzo que nadie recuerda es justamente lo que eso evita.

  > *Proveedores Exterior* copió el gesto para sus **vencidas**, y ahí el interruptor es puramente de vista: un pago vencido ya no suma en ninguna columna, así que esconderlo no cambia ningún total. Acá sí: lo excluido es plata que se decidió que no entra, y las tarjetas la descuentan. Ver `README-comex.md`.
- **Las tres tarjetas muestran el neto** —sin lo excluido—, que es lo mismo que suma la fila del tablero. Los dos totales los calcula PHP (`Echeqs::totalesNetos()`): el front no resta nada.

**No hay bajas físicas, y el historial es el punto.** Volver a incluir un cheque marca `VIGENTE = 0` y sella `FECHA_BAJA`; excluirlo de nuevo inserta una fila nueva. Con un `UPDATE`, un dedazo corregido a los cinco minutos y una decisión que estuvo vigente tres semanas son indistinguibles después del hecho, y la segunda es la que explica por qué el disponible proyectado de la semana pasada era otro. Volver a excluir algo ya excluido **no es un error**: es cómo se corrige un motivo mal escrito, y quedan los dos.

La crea `sql/cashflow_echeqs_excluir.sql`.

---

## Pruebas

```bash
php tests/run.php              # todo
php tests/run.php horizonte    # filtra por nombre de archivo
```

Cubren el eje temporal y su secuencia cronológica, las tres vistas y su criterio de columnas y totales, el validador de la estructura regla por regla, el arrastre del saldo con números conocidos, el módulo Saldos, y que un proveedor que lanza, que devuelve basura o que devuelve `null` no pueda tumbar el tablero. Las que necesitan SQL Server se saltean solas si no hay conexión.

De la **sección Cobertura**, `tests/test_cobertura.php` fija lo que la hace funcionar sin reglas nuevas en el motor: que el stock no vaya en ninguna columna de fecha y su total sea el mismo en las tres vistas; que **la posición de la fila de uso sea lo único** que separa los dos flujos netos —si alguien la mueve, esas pruebas se caen, que es exactamente lo que tienen que hacer—; que el arrastre la recoja y **el invariante `cierre[n] == apertura[n+1]` siga cerrando**; que no infle los indicadores de Ingresos ni de Egresos; y que **los subtotales anidados no dupliquen importes**, sobre un escenario con dos niveles de anidación.

Y del **saldo de cobertura**: que se mida sobre todo el horizonte y no sobre la vista —con un escenario que aplica en el tramo diario *y* en una columna mensual, donde el total del tramo diario es otro número—; que un uso negativo sume al disponible; que aplicar de más avise y no bloquee; y que sin fila de stock no se invente un disponible.

De `FLUJO_NETO`, `tests/test_cashflow.php` fija que incluya el saldo **mostrado** arriba y que no lo arrastre, y que `SALDO_FINAL` no lo cuente dos veces.

De los **fondos como cuentas**, `tests/test_cobertura.php` fija que el disponible se lleve por clave de cuenta —con un fondo sobregirado mientras el total cierra, que es el aviso que el pozo único no daba—, que un stock sin reparto y una aplicación con una clave que no es de ninguna cuenta se traten como corresponde, y que `por_fondo` **sobreviva a `series()`**, que es donde se perdió una vez. `tests/test_fondos.php` cubre el resto: ver `README-saldos.md`.

De la **cobertura automática**, `tests/test_cobertura_automatica.php` prueba primero `CoberturaAutomatica::calcular()` sola, con números, porque ahí viven todas las reglas: un día con flujo negativo pero caja de sobra **no rescata**; el rescate parcial saca exactamente lo que falta; las inversiones se agotan y recién ahí entra la comitente; los dólares se venden enteros hacia arriba (y un cociente exacto no sube uno por punto flotante; de USD 3,50 se venden 3); sin cotización no se vende; con los dos fondos agotados queda el faltante y ningún fondo va a negativo; la devolución es LIFO, acotada al sobrante del día y a lo rescatado, con una pila de varios rescates que se deshace en orden inverso; lo manual va primero, descuenta del tope, no se devuelve solo, y una devolución manual que deja rojo se cubre; el tope cambia con los movimientos previstos y un rescate previsto que se come lo usado deja el fondo sobregirado e informado; y el orden de consumo. Después lo enchufa al motor con dos filas de stock y dos de uso: que las celdas muestren manual más calculado, que el flujo con cobertura y el arrastre lo recojan sin aviso de descuadre, que el KPI y el saldo mínimo lo vean, que el desglose por columna y los totales separen los dos, que si no alcanza quede el faltante con su aviso, que un fondo sin fila de uso no se toque y se avise nombrando el script, que una fila informativa no calcule y que un stock sin tope no sea automatizable. Y lo de alrededor: `validarDisponible()` con la aplicación de la misma fecha que se pisa, la posterior que no cuenta y el negativo que pasa siempre; `Fondos::saldoProyectado()` y el tope por columna con un rescate previsto; `Cotizacion::ultimasHasta()` en dos consultas; `CoberturaProvider` con una `Cobertura` de mentira, repartiendo por clase y por columna; y el script y el registro.

De **Comercio Exterior**, `tests/test_comex_fecha_maestra.php` fija las reglas puras de esta etapa —cuándo una fecha está vencida (con hoy inyectado, para que la prueba no caduque sola), cuándo la marca de *editada* describe el valor que se ve, y el reparto de lo vencido entre lo que entra en la columna del mes en curso y lo que queda fuera del eje— y, **leyendo archivos**, el cableado que se rompe en silencio: que el filtro por fecha de embarque no vuelva, que las columnas `EDIT` no vuelvan a leerse, que el endpoint de fechas siga siendo uno solo, que el cliente no vuelva a mandar la fecha anterior, y que el buscador y la celda de fecha no se copien en las dos pestañas. Es el mismo criterio de `test_tablas_controles.php`. Ver `README-comex.md`.

> Esas pruebas leen el código **sin sus comentarios**, y eso no es un detalle: estos archivos explican en prosa lo que dejaron de hacer —*"antes era `COALESCE(FECHA_PAGO_EDIT, ...)`"*—, que es justamente lo que este módulo pide que se escriba. Buscando el patrón sobre el archivo entero, la única forma de pasar la prueba sería borrar la explicación.

**El motor acepta un `Horizonte` inyectado, y hace falta para poder probarlo.** El arrastre del saldo depende de qué día es hoy, así que un escenario con importes en fechas fijas deja de tener sentido en cuanto pasa esa fecha. Sin esa costura las pruebas del motor caducaban solas —y caducaron: 48 casos empezaron a devolver `null` al pasar el 06/09/2026, y la parte más delicada del módulo se quedó sin red. Es la misma costura que ya tenían `Ventas::proyectarVentas()` y `proyectarCobranzas()`.

```php
new Cashflow($estructura, $parametros, $horizonte)   // el horizonte es opcional
```

`test_cashflow.php` verifica la costura de forma explícita, para que si alguien la saca el mensaje de falla diga por qué fallan las otras noventa.

---

## Archivos

```
sql/cashflow_estructura.sql                 Las dos tablas de configuración + semilla
sql/cashflow_estructura_disponibilidades.sql  Reorganiza en Disponibilidades + Ventas
sql/cashflow_estructura_ingresos_egresos.sql  Los cuelga de Ingresos y Egresos; baja Ajustes
sql/cashflow_cobertura.sql                  Sección Cobertura: tipos, tabla y filas
sql/cashflow_saldos.sql                     Tablas del modulo Saldos (README-saldos.md)
sql/echeqs_prechequeado.sql                 Maestro de pre-chequeado + vista del neteo
sql/cashflow_echeqs_excluir.sql             Exclusion de cheques de cartera, con historial
cashflow/Class/Horizonte.php                Eje temporal, compartido con Ventas
cashflow/Class/EjeVista.php                 Las tres vistas: columnas, totales y periodo
cashflow/Js/eje-vistas.js                   Su contraparte en el front (cargado en index.php)
cashflow/Js/columnas-fijas.js               Que columnas quedan fijas al scrollear (idem)
cashflow/Js/tabla-orden.js                  Ordenar por encabezado, en las 36 tablas (idem)
cashflow/Js/tabla-export.js                 Exportar a Excel lo que se ve (idem)
cashflow/Js/notificaciones.js               Avisos de accion y confirmaciones (idem)
cashflow/Css/notificaciones.css
cashflow/Class/Menu.php                     Menu lateral y estado de cada pestana
cashflow/Class/CashflowProvider.php         Contrato de proveedor
cashflow/Class/CashflowRegistry.php         Registro de orígenes de datos
cashflow/Class/CashflowEstructura.php       Configuración: lectura, validación y CRUD
cashflow/Class/Cashflow.php                 El motor
cashflow/Class/Providers/VentasProvider.php
cashflow/Class/Providers/ComexProvider.php
cashflow/Class/Providers/IngresosProvider.php
cashflow/Class/Providers/SaldosProvider.php   Disponible inicial y caja de locales
cashflow/Class/Echeqs.php                   Cheques en cartera y venta cobrada anticipada
cashflow/Class/Providers/EcheqsProvider.php   Las tres series de cartera: ver su encabezado
cashflow/Controller/EcheqsController.php    Listados, marcado y exclusion de cheques
cashflow/Tabs/echeqs.php                    Las dos sub-pestanas
cashflow/Tabs/parametros_prechequeado.php   Maestro de clientes pre-chequeados
cashflow/Controller/CashflowController.php            getTablero
cashflow/Controller/CashflowEstructuraController.php  CRUD de la estructura
cashflow/Tabs/cashflow.php                  La pantalla
cashflow/Tabs/parametros_estructura.php     El editor
cashflow/Js/Cashflow.js
cashflow/Js/Parametros-Estructura.js
cashflow/Css/Cashflow.css
cashflow/Class/OtrosIngresos.php            Otros Ingresos, retirado (README-otros-ingresos.md)
cashflow/Class/Providers/OtrosIngresosProvider.php
sql/cashflow_saldo_inversiones.sql
sql/RO_V_DOLAR_OFICIAL_BCRA_DIARIO.sql      Cotización diaria, para valuar los dólares
cashflow/Class/Cobertura.php                Aplicación de inversiones para cubrir baches; los fondos son las cuentas
cashflow/Class/Fondos.php                   Cuentas de inversión y comitente (README-saldos.md)
cashflow/Class/Providers/FondosProvider.php Stock de cobertura por cuenta de fondo
sql/cashflow_saldos_cuentas_fondo.sql       CLASE, saldo inicial, movimientos y la migración desde Otros Ingresos
cashflow/Class/Providers/CoberturaProvider.php   Lo cargado a mano, una serie por clase de fondo; no declara pestaña
cashflow/Class/CoberturaAutomatica.php           El algoritmo del uso de cobertura, puro
sql/cashflow_cobertura_automatica.sql            Una fila de uso por fondo; clave fecha + fondo
cashflow/Controller/CoberturaController.php      Se llama desde el tablero, no desde una pestaña
cashflow/Class/Providers/ExportacionesProvider.php   Exportaciones Tasky (README-exportaciones-tasky.md)
cashflow/Tabs/exportaciones_tasky.php
sql/cashflow_exportaciones_tasky.sql
cashflow/Class/Comex.php                    Comercio Exterior (README-comex.md)
cashflow/Js/Comex-fechas.js                 La celda editable, el tilde de pagado y el buscador, de las dos pestanas
sql/cashflow_comex_fecha_maestra.sql        La fecha vive en el maestro, con rastro de quien edito
sql/cashflow_comex_pagado.sql               Que pagos ya se hicieron: salen de la proyeccion, con historial
tests/                                      Arnés de pruebas
```

Modificados: `Class/Ventas.php` (delega el eje y acepta uno inyectado) · `Class/Ingresos.php` (`getCobranzasFRTotales`; fecha manual también en mayoristas, y los métodos pierden el sufijo `FR`) · `Class/Parametros.php` (registro del módulo) · `Tabs/parametros.php` (navegación generada) · `Js/Parametros.js` (guardas contra null) · `Js/main.js` (`pedirJson`) · `index.php`, `TabController.php`, `Components/sidebar.php`, `Components/header.php` (el reemplazo de Resumen).

De la rama `feature/cashflow-estructura-inversiones`: `Class/Cashflow.php` (el saldo mostrado entra en `FLUJO_NETO` y en el indicador de Ingresos; el stock de cobertura sale de las columnas; la cobertura va aparte en el KPI) · `Class/CashflowEstructura.php` (los dos tipos nuevos) · `Class/CashflowRegistry.php` (`COBERTURA`; serie `STOCK` en `SALDO_INVERSIONES`) · `Class/Cotizacion.php` (`ultimaHasta()` y la vista diaria) · `Class/OtrosIngresos.php` (`valuarDolares()`, la cuenta única) · `Providers/OtrosIngresosProvider.php` · `Controller/OtrosIngresosController.php` · `Js/Cashflow.js` y `Css/Cashflow.css` (celda editable, stock en guiones, columnas negativas) · `Js/Parametros-Estructura.js` (rótulos de los tipos nuevos) · `Tabs/cashflow.php`, `Tabs/dolares_comitente.php`, `Tabs/saldo_inversiones.php` · `Js/Ingresos-Cobranzas_may.js` y su CSS (editor de fecha manual) · `sql/cashflow_saldo_inversiones.sql` y `sql/cashflow_cobranzas_fecha_manual.sql` (encabezados reescritos: decían lo contrario de lo que hace el código).

De la rama `feature/cuentas-inversion`: `sql/cashflow_saldos_cuentas_fondo.sql`, `Class/Fondos.php`, `Providers/FondosProvider.php` y `tests/test_fondos.php` (nuevos) · `Class/CashflowProvider.php` (`por_fondo` y `fondos` en el contrato, y `normalizar()` las deja pasar) · `Class/Cashflow.php` (`resolverCobertura()` cruza por clave de cuenta; aviso por módulo retirado) · `Class/CashflowRegistry.php` (`FONDO_INVERSION`, `FONDO_COMITENTE`; `'retirado'` y `retirado()`; Otros Ingresos sin `origen_cobertura`) · `Class/CashflowEstructura.php` (advertencia por módulo retirado) · `Class/Cobertura.php` (`origenes()` y los helpers puros sobre la lista; se fueron `ORIGENES`, `MONEDA_POR_FONDO` y `ORIGEN_DEFECTO`) · `Providers/CoberturaProvider.php` (`por_fondo` y los nombres; aviso por aplicaciones sin cuenta) · `Controller/CoberturaController.php` (los orígenes salen de las cuentas) · `Class/Saldos.php`, `Controller/SaldosController.php`, `Tabs/saldos.php`, `Js/Saldos.js`, `Css/Saldos.css`, `Tabs/parametros_saldos.php`, `Js/Parametros-Saldos.js`, `Class/Parametros.php`, `Controller/ParametrosController.php` (ver `README-saldos.md`) · `Class/Menu.php` (se va la categoría Otros Ingresos) · `Js/Parametros-Estructura.js` (los módulos retirados, marcados) · `Controller/TabController.php` (sin las dos pestañas) · **eliminados** `Tabs/dolares_comitente.php`, `Tabs/saldo_inversiones.php`, sus JS y CSS y `Controller/OtrosIngresosController.php` · `tests/test_cobertura.php`, `tests/test_otros_ingresos.php`, `tests/test_menu.php`, `tests/test_providers.php`.

De la rama `feature/echeqs-excluir`: `sql/cashflow_echeqs_excluir.sql` (nuevo) · `Class/Echeqs.php` (la exclusión entera, y el corte por excluido en las dos consultas de cartera) · `Providers/EcheqsProvider.php` (tres series en vez de una; `seriesDeItem()` y `repartir()` estáticas y puras, para poder verificar el corte sin depender de que haya algo excluido) · `Class/CashflowRegistry.php` (las tres series y su `componentes`) · `Controller/EcheqsController.php` (`excluirCheques`, `getHistorialExclusion`, y los totales netos en el payload) · `Tabs/echeqs.php`, `Js/Ingresos-Echeqs.js`, `Css/Ingresos-Echeqs.css` (el interruptor, la barra de selección y el diálogo del motivo) · `tests/test_echeqs.php`.

> En esa rama salió además un **bug que ya estaba en `develop`**: `Echeqs::marcarCheques()` armaba la lista de ids con `array_values()` sobre un mapa **indexado por id**, así que lo que viajaba a la consulta era una lista de `true` —que SQL Server convierte a `1`— y las veinte marcas terminaban todas sobre el cheque `1`. Nunca se ejecutó: `RO_T_CASHFLOW_ECHEQ_PRECHEQ` está vacía, así que no hay ningún dato que reparar. Se corrigió junto con la exclusión porque es la misma función que ésta reusa, y se dejó la nota en las dos.

De la rama `feature/cobertura-automatica`: `Class/CoberturaAutomatica.php`, `sql/cashflow_cobertura_automatica.sql` y `tests/test_cobertura_automatica.php` (nuevos) · `Class/Cashflow.php` (`resolverUsoCobertura()` antes del arrastre; `sumarMovimientos()` con exclusión de tipo; `resolverCobertura()` separa manual de calculado y los avisos pasan a `avisarCobertura()`; el segundo invariante; `fondos_tope` y `fondos_manual` no salen en el JSON) · `Class/CashflowProvider.php` (`fondos_tope` y `fondos_manual` en el contrato) · `Providers/FondosProvider.php` (`fondos_tope`; `fechasPorColumna()` y `tope()` estáticos) · `Providers/CoberturaProvider.php` (una serie por clase; `fondos_manual`; `cobertura()` por fábrica) · `Class/CashflowRegistry.php` (las tres series de `COBERTURA` y su `componentes`) · `Class/Cobertura.php` (clave fecha + fondo; `validarDisponible()` y `disponibleParaAplicar()`; `saldoFondoA()`; historial por fondo) · `Class/Fondos.php` (`saldoProyectado()`; `getCuentasFondo()` con movimientos a pedido) · `Class/Cotizacion.php` (`ultimasHasta()` y `entreFechas()`) · `Controller/CoberturaController.php` (origen en baja e historial) · `Js/Cashflow.js` y `Css/Cashflow.css` (dos filas de uso, manual vs. calculado, editor por fondo y en su moneda) · `tests/test_cobertura.php` y `tests/test_fondos.php` (las series nuevas y los dobles).

De la rama `feature/comex-fecha-maestra`: `sql/cashflow_comex_fecha_maestra.sql`, `Js/Comex-fechas.js`, `tests/test_comex_fecha_maestra.php` y `README-comex.md` (nuevos) · `Class/Comex.php` (las dos fechas se escriben sobre `RO_T_IMPORTACIONES_ENCABEZADO`; `guardarFecha()` reemplaza a `updateFechaPago()` y `updateFechaNacPago()`; se fue el filtro por fecha de embarque; `estaVencida()`, `marcaVigente()` y `avisosVencidos()` puras; el orden por fecha efectiva con los nulos al final) · `Providers/ComexProvider.php` (se fue `AVISO_FILTRO_EMBARQUE`; los avisos de vencidos) · `Controller/ComexController.php` (un solo `updateFecha`, más `getHistorialFecha`) · `Js/Comex-Proveedores_exterior.js` y `Js/Comex-Crono_nacionalizacion.js` (delegan la celda, el editor y el buscador en el archivo compartido) · `Tabs/proveedores_exterior.php` (buscador y `data-exportar`), `Tabs/crono_nacionalizacion.php` · sus dos CSS (las marcas de vencida y sin fecha).

De la rama `feature/comex-pagado`: `sql/cashflow_comex_pagado.sql` (nuevo) · `Class/Comex.php` (`marcarPagado()`, `getHistorialPagado()`, `tienePagado()`; `importeProyectable()` y `aporteAlEje()` separadas; la regla de vencidos también en Crono) · `Providers/ComexProvider.php` (tres series por código en vez de una; `soloPagadas()` pura; la guarda del "sin gastos cargados" se mide sobre el importe crudo y se va `totalSerie()`) · `Class/CashflowRegistry.php` (las seis series de Comex y sus `componentes`) · `Controller/ComexController.php` (`marcarPagado`, `getHistorialPagado`) · `Js/Comex-fechas.js` (la celda del tilde, su guardado y el tercer filtro) · los dos JS de pestaña, sus dos CSS y sus dos Tabs (columna *Pagado*, interruptor *Ver pagados* y el de *Ver vencidas* en Crono) · `Components/help_modal_comex.php` · `tests/test_comex_fecha_maestra.php`.

Eliminado: `Tabs/resumen.php`.

---

## Pendientes conocidos

- **Una aplicación manual no es un movimiento del fondo.** El motor y las cargas manuales son *proyección*: cuando el rescate se hace de verdad, la plata sale del fondo y entra al banco, y eso lo tienen que reflejar un `RESCATE` en Saldos → Fondos y el saldo bancario del día siguiente. Una carga manual con fecha pasada no descuenta del stock a hoy (`Fondos::saldoA()` no la conoce) ni entra al saldo proyectado (su columna ya no está en la secuencia): queda en su celda y en el "queda X de Y", nada más. Hoy hay una así en la base —`$ 4.000.000` de *Inversiones* el 18/09, más que el fondo—, y **bloquea cualquier carga manual nueva desde ese fondo** porque `validarDisponible()` la descuenta: hay que darla de baja desde el tablero (o registrar el rescate real). Qué hacer con una aplicación cuando su fecha pasa —convertirla en movimiento, darla de baja sola, dejarla— es una decisión pendiente.
- **Las columnas mensuales son un solo paso para el motor.** Un bache a mitad de un mes fuera del tramo diario que se tape solo antes de fin de mes no se ve, y uno que no se tape se rescata "en el mes", sin día. Es la resolución del eje, no del algoritmo; si hace falta más, se alarga `horizonte_dias`.
- **La cuenta comitente migró con `USD 1,00` como saldo inicial** y después se corrigió a `USD 66.000,00` desde Parámetros → Saldos (verificado el 19/09/2026); la foto de 71.000 del 16/09 sigue en `RO_T_CASHFLOW_DOLARES_COMITENTE`. Con la cobertura automática ese saldo importa: es lo que el motor vende cuando las inversiones no alcanzan.
- **El saldo de apertura ya no arranca en cero, pero depende de que alguien cargue.** El módulo Saldos existe (ver `README-saldos.md`) y alimenta *Saldo Inicial*. Mientras no haya ninguna carga, o mientras la última quede vieja, la fila va en cero o desactualizada y **el tablero lo avisa con la fecha del dato**: leer esos saldos como disponibilidad real sería un error caro.
- **Todas las filas del Excel ya tienen de dónde salir.** *Exportaciones Tasky* fue la última: la alimenta `ExportacionesProvider` con las facturas pendientes en dólares de `GVA12` (ver `README-exportaciones-tasky.md`). *Caja Locales* salió de esta lista cuando se construyó el módulo Saldos, y *Dólares Cuenta Comitente* y *Saldo de Inversiones* primero con **Otros Ingresos** y después como **cuentas de fondo de Saldos** (ver `README-saldos.md`): se cargan a mano —un saldo inicial y los movimientos—, pero por una pantalla y no por el Excel, así que siguen entrando al tablero por un proveedor como cualquier otra.
- **El neteo de cheques adelantados resta importes que ninguna fila del tablero suma.** Un cheque en cartera cierra solo: suma en *Echeqs en cartera* y resta de la cobranza de Ventas. Uno ya aplicado —depositado o endosado a un proveedor— no lo suma nadie, y se netea igual: **el neteo va por tilde y no por estado**, porque los cheques pre-chequeados están casi todos aplicados y filtrarlos dejaría el circuito sin efecto. Es una decisión tomada, no un pendiente; el pie de la sub-pestaña muestra el corte por estado para poder auditar el número. El detalle de lo verificado contra la base está en `README-ventas.md`.
- **`Ingresos::getCobranzasFR()` sigue haciendo una consulta por fila** en *Detalle Facturas*, para traer la fecha de emisión de cada comprobante. El tablero no lo sufre —usa `getCobranzasFRTotales()`— y el Resumen tampoco, que desde que no muestra esa columna se la saltea; lo paga *Detalle Facturas*, que es donde se pidió el detalle, **y el Resumen cuando hay filtro por fecha de emisión**, porque ahí esa fecha es lo que decide si la fila entra.
- **El Dashboard es una maqueta**: no tiene ninguna llamada al servidor, sus números están escritos a mano. El menú lo marca como tal. Cuando se construya de verdad, hay que pasarlo a `datos` en `Class/Menu.php`.
- **Las fechas de Comercio Exterior se graban con `USUARIO = NULL`**, como todo lo demás, y el rastro de quién editó existe pero **todavía no tiene pantalla que muestre el historial completo**: la celda muestra sólo la edición vigente en su tooltip. `getHistorialFecha` ya lo devuelve entero. Ver `README-comex.md`.
- **`nacionalizacion_2` está en `$validTabs` de `TabController` pero no tiene archivo ni entrada de menú.** Es configuración muerta: nadie puede llegar ahí, y si llegara vería el placeholder.
- **`VentasController?action=saveMixCobro` puede grabar un mix que Parámetros rechazaría**: no valida el 100%. Es anterior a este trabajo.
- `pedir()` está duplicado en `Ingresos-Ventas.js` y `Parametros.js`. El código nuevo usa `pedirJson()` de `main.js`; sacar las dos copias viejas es un cambio aparte.
- **Algunas pestañas de datos todavía usan `alert()`.** `Js/notificaciones.js` está enchufado en toda la pestaña Parámetros, en Cobranzas FR, en Otros Ingresos y —desde la exclusión de cartera— en la sub-pestaña *Cheques en Cartera* de Echeqs; está disponible para el resto. Las dos pestañas de Comex también lo usan desde `feature/comex-nac-usd` —los nueve `alert()` que les quedaban, con la falla de carga pintada además adentro de la tabla vacía; ver `README-comex.md`—. Ventas, Saldos, Cob. Electrónicos y Cobranzas May siguen con el diálogo del navegador, y **la otra sub-pestaña de Echeqs sigue con un `confirm()` en el tildado masivo**: quedó así a propósito, porque cambiarla no es parte de la exclusión y mezclarla habría metido en esa etapa un archivo que no tiene nada que ver con ella. Es el mismo reemplazo, archivo por archivo.
- Sin login: todo se graba con `USUARIO = NULL`. La costura ya está puesta.
