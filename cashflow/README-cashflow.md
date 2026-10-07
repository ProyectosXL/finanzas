# Módulo Cashflow — motor de consolidación

Tablero que consolida el flujo de fondos de todos los módulos. Reemplaza a la pantalla Resumen.

Rama: `feature/cashflow-dinamico`

> **Todo el módulo vive dentro de `cashflow/`**: el código, los scripts SQL, las pruebas, estos README y `docs/`. Lo único que queda afuera es lo que se comparte con los otros módulos del repo —`class/conexion.php`, `class/classEnv.php` e `images/`, que usa también `599/`—.
>
> **Las rutas de estos README son relativas a `cashflow/`**: cuando dicen `sql/cashflow_tarjetas.sql` o `Class/Horizonte.php`, el archivo está en `cashflow/sql/…` y `cashflow/Class/…`. Los avisos que la pantalla muestra usan la misma convención, así que un archivo se nombra igual en los dos lugares. Los comandos, en cambio, van **desde la raíz del repo** —`php cashflow/tests/run.php`—, porque un comando se ejecuta desde algún lado y hay que decir desde dónde.

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

**Todos van contra `central`, salvo uno de la sección que sigue**, que va contra `apps`. Todos son reejecutables y ninguno borra nada: lo que reemplazan queda inhabilitado. Si no se corren, la pantalla **no falla** — avisa. **Esa sección es la excepción también en esto**: sus scripts van antes que el código, y sin ellos el código nuevo sí falla.

### Primero que nada: auditoría y permisos de escritura (`feature/cashflow-auditoria-usuario`)

**Acá el orden es al revés que en el resto de esta sección: primero los scripts, después el código.** Sin las columnas nuevas, las escrituras del código nuevo **fallan** —no hay vuelta a las columnas viejas— y algunas lecturas también. Con los scripts corridos y el código viejo todavía publicado, en cambio, no pasa nada: el código viejo no lee las columnas nuevas, y las nuevas son NULL o tienen default.

**Un script va contra `apps`**, la base de Gestionusuarios. Es el único del módulo que no va contra `central`.

| # | Base | Script | Qué hace | Si no se corre |
| --- | --- | --- | --- | --- |
| A1 | `apps` | `sql/cashflow_permisos_edicion.sql` | Da de alta `cashflow.editar.<pestaña>` para las 13 pestañas con escrituras y `cashflow.editar.parametros.<sub>` para las 11 sub-pestañas de Parámetros. **Migra sin quitarle nada a nadie**: todo rol que ve una pestaña recibe su edición, y todo rol que ve Parámetros recibe las 11 | **Sólo el administrador puede guardar.** Todos los demás ven las pantallas sin controles de edición, y si llaman al endpoint igual, el servidor responde 403 |
| A2 | `central` | `sql/cashflow_auditoria_usuario.sql` | Lleva a 42 tablas el esquema `USUARIO_ALTA` / `FECHA_ALTA` / `USUARIO_MODIF` / `FECHA_MODIF` (+ `USUARIO_BAJA` / `FECHA_BAJA` donde hay baja lógica) y copia lo que ya había (ver *Auditoría y permisos de escritura*) | **Las escrituras fallan** con `Invalid column name 'USUARIO_ALTA'`. Y peor, en silencio: `Ingresos::getFechasManuales()` devuelve vacío si su consulta falla, así que **las fechas de cobro manuales de Cobranzas FR y May dejan de aplicarse sin aviso** |
| A3 | `central` | `sql/RO_SP_CASHFLOW_COMEX_RECEP_HIST.sql`, `sql/RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN.sql`, `sql/RO_SP_CASHFLOW_VENTAS_HIST_DIA.sql`, `sql/SJ_CASHFLOW_VENTAS_HIST.sql` | **Modificados: hay que volver a correrlos.** Dejan de grabar `SUSER_SNAME()` —la cuenta del servicio, que no dice quién—: desde la pantalla reciben el username; desde el job, `JOB:<proceso>`; vacíos, `JOB:<nombre del SP>`. Escriben `USUARIO_ALTA` en sus tablas y en `RO_T_CASHFLOW_JOB_LOG`. **Dependen del A2** | Los SP viejos siguen andando —escriben `JOB_LOG.USUARIO`, que sigue existiendo— pero la pantalla ya no lee esa columna: *Proyección* muestra "Calculado" sin decir por quién |
| A4 | SQL Agent | Los pasos de los jobs | Que el paso pase su origen. Ver abajo | El SP graba `JOB:<nombre del SP>` en vez del nombre corto. Se lee igual —los dos están en `AuthCashflow::ORIGENES`—, sólo que más largo |

**El A2 ya se corrió contra esta base `central` el 02/10/2026.** Es reejecutable: una segunda corrida no agrega nada y no pisa ninguna copia.

Los pasos de los jobs (A4), para quien administra el SQL Agent:

```sql
EXEC msdb.dbo.sp_update_jobstep @job_name = N'CASHFLOW - Historia de recepciones Comex', @step_id = 1,
     @command = N'EXEC dbo.RO_SP_CASHFLOW_COMEX_RECEP_HIST @Usuario = ''JOB:COMEX_RECEP_HIST'';';
EXEC msdb.dbo.sp_update_jobstep @job_name = N'CASHFLOW - Presupuesto de compras Comex', @step_id = 1,
     @command = N'EXEC dbo.RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN @Usuario = ''JOB:COMEX_PRESUP'';';
-- El job que corre el historico de ventas (el nombre lo sabe quien lo creo):
--   EXEC dbo.SJ_CASHFLOW_VENTAS_HIST @Usuario = 'JOB:VENTAS_HIST';
--   EXEC dbo.RO_SP_CASHFLOW_VENTAS_HIST_DIA @Usuario = 'JOB:VENTAS_HIST_DIA';
```

**La migración del A1 da editar a todos los que hoy ven.** Es a propósito: el día del pase nadie pierde nada. **Lo que haya que restringir se recorta después desde Gestionusuarios**, quitándole al rol la clave `cashflow.editar.*` que no corresponda. Y por lo mismo, **no hay que volver a correr el A1 después de recortar**: la migración volvería a dar editar a quien ve.

#### Después de correrlos, verificar

En `apps`, que estén las 24 claves y cuántos roles tiene cada una (el script mismo termina con esta tabla):

```sql
SELECT p.clave, COUNT(rp.rol_id) AS roles
FROM dbo.FP_PERMISOS p
LEFT JOIN dbo.FP_ROL_PERMISO rp ON rp.permiso_id = p.id
WHERE p.clave LIKE 'cashflow.editar.%'
GROUP BY p.clave
ORDER BY p.clave;
```

Y que el `modulo_id` de las claves nuevas sea el 7: es el que lee `AuthCashflow`. Si el script avisó que el módulo es otro, las claves están pero el PHP no las ve.

En `central`, el script termina con un `SELECT` de **qué columna le falta a qué tabla**: tiene que volver vacío. Después de publicar el código, una edición cualquiera tiene que dejar su firma:

```sql
SELECT TOP 5 ID_MG, CAMPO, USUARIO_ALTA, FECHA_ALTA, USUARIO_BAJA, FECHA_BAJA
FROM dbo.RO_T_CASHFLOW_COMEX_FECHA_EDIT ORDER BY ID DESC;

SELECT TOP 5 PROCESO, INICIO, USUARIO_ALTA, USUARIO_MODIF
FROM dbo.RO_T_CASHFLOW_JOB_LOG ORDER BY ID DESC;   -- JOB:... o un username, nunca la cuenta del servicio
```

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
| 22 | `sql/cashflow_estructura_grupos.sql` | Agrega `GRUPO`, `NATURALEZA` y `GRUPO_NOMBRE` a `RO_T_CASHFLOW_CONF_FILA`, y **agrupa las dos filas de Cobranzas Franquicias** en un solo renglón que se abre. De paso declara la naturaleza de las tres filas que son enteras de una parte | **El tablero funciona como hasta ahora**, con una fila por cada parte y sin ningún renglón que se abra, y lo avisa nombrando el script. Ningún importe cambia: la agrupación es presentación. Los campos de *Parámetros → Cashflow* se dibujan apagados diciendo qué falta |
| 23 | `sql/cashflow_compras_proyectadas.sql` | Crea las filas **Proveedores Exterior Proyectado** y **Nacionalizaciones Proyectado** (órdenes 15 y 25, pegadas a su parte real), asigna los grupos `PROV_EXTERIOR` y `NACIONALIZACIONES`, crea `RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE` y siembra los siete parámetros del módulo `COMPRAS_PROY` | **El tablero queda exactamente como hoy**: sin parte proyectada y sin ningún número cambiado. La pestaña *Comercio Exterior › Proyección* avisa qué script falta y no rompe. Ver `README-compras-proyectadas.md` |
| 24 | `sql/cashflow_comex_materializado.sql` | Crea `RO_T_CASHFLOW_JOB_LOG` y las tres tablas que materializan los insumos pesados de Compras Exterior: historia de recepciones, presupuesto oficial por temporada y contraste de la vista | **Las dos filas proyectadas van en CERO** y el primer aviso dice que se proyecta DE MENOS y qué job falta. No hay vuelta a la consulta en vivo. Ver la sección 10 de `README-compras-proyectadas.md` |
| 25 | `sql/RO_SP_CASHFLOW_COMEX_RECEP_HIST.sql` | `CREATE OR ALTER` del SP que llena la historia de recepciones (diez años, ~1 s). Al pie, la programación sugerida para el SQL Agent | Igual que el 24: la historia queda vacía y las filas proyectadas en cero, avisando |
| 26 | `sql/RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN.sql` | `CREATE OR ALTER` del SP que trae el presupuesto oficial y el contraste por el linked server `[XL-APPS]` (~0,5 s). Al pie, la programación sugerida | Igual que el 24, para el presupuesto |
| 27 | `sql/cashflow_prov_locales_fuente_fecha.sql` | Agrega `FUENTE_FECHA` a `RO_T_CASHFLOW_PROV_LOCALES_PAGO` —si cada fecha de pago se cargó a mano o vino de la planilla— y la rellena desde `ORIGEN` en las filas que ya tienen fecha | **El tablero no cambia**. La columna *Fecha de pago* de Proveedores Locales distingue igual lo importado de lo manual, leyendo `ORIGEN`, y la pestaña avisa. Lo que se pierde es que la marca siga siendo cierta cuando se excluye o cambia de forma una factura con fecha importada. Ver `README-proveedores-locales.md` |
| 28 | `sql/cashflow_prov_exclusion_modulo.sql` | Crea `RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO`: qué proveedores quedan fuera de un módulo porque su deuda ya se considera en otra pestaña, con motivo e historial. Hoy el único módulo es Proveedores Locales | **El tablero no cambia**: nace vacía. Sin ella nadie está excluido, el maestro dibuja la columna con un guion y la pestaña avisa. Ver `README-proveedores-locales.md` |
| 29 | `sql/cashflow_parametros_generales.sql` | Mueve a un módulo `GENERALES` los parámetros que hoy figuran como de Ventas —`horizonte_dias`, `horizonte_meses`, `alicuota_iva`, `feriados_comercio`—, **sin tocar ningún VALOR**, y crea `RO_T_CASHFLOW_INFLACION_MES` (el % esperado de cada mes calendario) y `RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT` (los overrides de las fechas de pago) | **Ningún cálculo cambia** y nada se rompe: el único lugar que pide parámetros por MODULO es la pantalla de Parámetros; las fórmulas los leen por clave. Los cuatro parámetros **se siguen viendo y editando** en la sub-pestaña Generales, que los rescata de `VENTAS/GENERAL` y avisa qué script falta. Lo que no se puede es cargar inflación —los valores hora de Logística se proyectan sin ajuste trimestral— ni mover una fecha del cronograma, y las dos tarjetas lo dicen. Ver `README-logistica-local.md` |
| 30 | `sql/cashflow_logistica_fleteros.sql` | Crea `RO_T_CASHFLOW_LOGISTICA_FLETEROS`: quién es fletero, cuántas horas por mes trabaja y cuánto vale su hora. **No siembra ninguno** | **El tablero no cambia**: la fila `LOGISTICA` ya existe y ya apunta a `LOGISTICA/PAGOS`, así que sigue en cero —que es lo que va hoy— y ahora además el proveedor **avisa por qué**. La pestaña Logística Local se lee igual, con la planilla vacía, y la sub-pestaña de alta se dibuja apagada nombrando este script. Ver `README-logistica-local.md` |
| 31 | `sql/cashflow_tarjetas.sql` | Crea `RO_T_CASHFLOW_TARJETAS` y `RO_T_CASHFLOW_TARJETAS_RESUMEN`: el maestro de tarjetas y un resumen por tarjeta y mes. **No siembra ninguna tarjeta**. NO crea la vista `RO_V_CASHFLOW_USUARIOS_TARJETAS`, que ya existe y se mantiene aparte: sólo verifica que esté | La pestaña *Financiero → Pagos con Tarjetas y Otros* **no falla**: las tres sub-pestañas se ven vacías avisando qué script falta, y *Parámetros → Tarjetas* se dibuja apagada. La fila del tablero va en **cero** y el proveedor lo dice, aclarando que un cero no significa que no haya que pagar nada. Ver `README-pagos-tarjetas.md` |
| 32 | `sql/cashflow_tarjetas_facturas.sql` | Crea `RO_T_CASHFLOW_TARJETAS_FACTURA` —qué factura se paga con qué tarjeta— y `RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA`, con su historial. **Depende del 31**: tiene una FK contra el maestro, y corta con un mensaje si falta | *Tarjetas Pagos Corporativos* **se lee igual** —las facturas salen de Tango— pero no se puede vincular ni excluir ninguna: los botones de la barra de selección se dibujan apagados nombrando el archivo, no hay cobertura —se calcula sobre las vinculadas— y nadie está excluido, que es lo cierto |
| 33 | `sql/cashflow_tarjetas_fila.sql` | Crea la fila **Pagos con Tarjetas y Otros** en *Costos Indirectos*, con `ORDEN = 45` —entre Impuestos (40) y el subtotal (50)—, apuntada a `TARJETAS` / `TOTAL`. Al pie verifica que ninguna fila activa de Proveedores Locales use `PAGOS_TODO` ni `PAGOS_FUERA_CRONOGRAMA` | **El tablero queda exactamente como hoy** y no falta nada, porque hasta hoy esta plata no estaba en el cuadro. La pestaña funciona igual —se lee, se carga y se calcula todo— y lo único que pasa es que lo que muestra no llega al tablero |

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
- `COBRANZAS_FR_REAL` y `COBRANZAS_FR_PROY` **seguidas**, con `GRUPO = 'COBRANZAS_FR'` y una `NATURALEZA` cada una. El agrupamiento es posicional: si alguien mete una fila activa entre las dos, el tablero las dibuja sueltas y el validador lo avisa. Que la fila total `COBRANZAS_FR` esté inactiva **entre medio** no molesta: no se dibuja.
- `PROV_EXTERIOR_PROY` y `NACIONALIZACIONES_PROY` **activas**, en los órdenes 15 y 25, cada una **pegada** a su parte real (10 y 20). Los dos grupos —`PROV_EXTERIOR` y `NACIONALIZACIONES`— con una fila `REAL` y una `PROYECTADO` cada uno. Sus series **nunca** se suman a las de `COMEX_PROV_EXT` ni `COMEX_NAC`: son universos disjuntos, no partes de un total, y por eso el registro no las declara como `componentes`. Con las dos filas inactivas, cada grupo queda de una sola fila y el tablero las dibuja sueltas, que es lo correcto y no un error.
- **Dos filas de saldo**: `SALDO_ACUM_SIN_COB` en *Resultados* justo debajo de *Flujo Neto (sin cobertura)*, y `SALDO_FINAL` al final de *Cobertura*. Si la de arriba quedara **debajo** de las filas de uso las incluiría y diría lo mismo que la del final.
- **Ningún parámetro en `MODULO = 'VENTAS'` con `GRUPO = 'GENERAL'`.** El script 29 lo verifica e imprime el resultado. La sub-pestaña Ventas ya no dibuja generales, así que un parámetro que quede ahí **existe y no se ve en ninguna pantalla**. Ningún importe cambia por moverlos: las fórmulas leen por clave.
- **`LOGISTICA` activa, con serie `PAGOS`**, en *Costos Directos*. Esa fila ya existía y no la toca ningún script: lo que cambió es que ahora tiene proveedor. **El día que se corre el script 30 el tablero no se mueve un peso**, porque el maestro nace vacío; se mueve cuando se cargan los fleteros con sus horas y su valor hora. Y antes de eso hay que **excluirlos de Cuentas a Pagar Locales** desde el maestro de Proveedores Locales: su deuda real ya está en el tablero, así que mientras no estén excluidos el mismo pago se cuenta dos veces y **el cuadro cierra igual**. La pestaña Logística Local y el tablero lo avisan mientras falte. Ver `README-logistica-local.md`.
- **`TARJETAS_PAGOS` activa y sola**, en *Costos Indirectos* con `ORDEN = 45`, apuntada a `TARJETAS` / `TOTAL`. Las otras cuatro series del proveedor —`SUPERVISORAS`, `CORPORATIVAS`, `SOCIOS` y `CORPORATIVAS_EXCLUIDAS`— quedan declaradas y **sin usar**, para poder partir la fila desde Parámetros el día que se quiera. El registro declara `TOTAL = [SUPERVISORAS, CORPORATIVAS, SOCIOS]`, así que activar una parte al lado del total lo rechaza el validador; `CORPORATIVAS_EXCLUIDAS` **no** es componente y sí puede convivir con él, porque su importe no está en el total.
- **Ninguna fila activa de Proveedores Locales con serie `PAGOS_TODO` ni `PAGOS_FUERA_CRONOGRAMA`.** Las dos incluyen las facturas de tarjeta corporativa, que desde el script 33 entran al cuadro por *Pagos con Tarjetas y Otros*: el mismo peso se contaría dos veces y **el cuadro cerraría igual**, así que nada lo delataría. **El validador no lo puede ver** —son dos proveedores distintos y el solapamiento es de datos, no de series— así que el control lo hace el propio script al correr, y `ProveedoresProvider` lo avisa en cada carga separando lo que sí entra por la otra fila de lo que no entra por ninguna. Verificado contra la base el 26/09/2026: la fila usa `PAGOS` y no hay doble conteo. Ver `README-pagos-tarjetas.md`.
- **El día que se corren los scripts 31 a 33 el tablero SÍ se mueve**, a diferencia de Logística. Desde `feature/cronogramas-tarjetas-corporativas`, **ninguna factura corporativa sin tarjeta vinculada entra al flujo**, así que lo que entra solo es la parte en efectivo de las supervisoras; las facturas entran a medida que se vinculan. Lo que NO entra hasta que se carguen las tarjetas son los **$ 60,1 millones** de parte tarjeta de las supervisoras y todas las facturas corporativas sin vincular (al 26/09/2026, **$ 89,9 millones** en 135 vencimientos), y el proveedor lo avisa con el importe.

### Cronogramas por concepto y Tarjetas Pagos Corporativos (`feature/cronogramas-tarjetas-corporativas`)

Dos scripts nuevos, los dos contra `central`, reejecutables y sin borrar nada. **Van después del A1/A2 de auditoría**, como todo lo que escribe con las seis columnas.

| # | Script | Qué hace | Si no se corre |
| --- | --- | --- | --- |
| 34 | `sql/cashflow_cronograma_conceptos.sql` | Siembra los seis parámetros del cronograma por concepto —día y frecuencia de `PROV_LOCALES` (miércoles, quincenal), `LOGISTICA` (viernes, quincenal) y `SUPERVISORAS` (lunes, semanal)— en `GENERALES` / `CRONOGRAMA`, y a `RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT` le suma `TIPO` (los overrides existentes quedan `LOGISTICA`), el `CHECK` de `NRO_PAGO` 1..5 y el índice único `(TIPO, MES, NRO_PAGO) WHERE VIGENTE = 1`. El `CHECK` y el índice se recrean sólo si tienen la forma vieja | **Cada concepto usa su valor por defecto**, que es el mismo que siembra el script, y *Parámetros › Generales* lo avisa. No se puede cambiar el día ni la frecuencia, ni mover a mano un pago de Proveedores Locales o de Supervisoras: los overrides que ya existen siguen valiendo para Logística. **Ojo: con el código publicado, el efectivo de Gastos Supervisoras pasa a los lunes y Cuentas a Pagar Locales a los días de pago aunque el script no se corra**, porque ése es su defecto |
| 35 | `sql/cashflow_tarjetas_vto_mensual.sql` | Crea `RO_T_CASHFLOW_TARJETAS_FACTURA_VTO` (el vencimiento corregido de una cuota) y `RO_T_CASHFLOW_TARJETAS_MENSUAL` (las facturas marcadas como mensuales, con FK al maestro de tarjetas). **Corta con mensaje si falta `sql/cashflow_tarjetas.sql`** | *Tarjetas Pagos Corporativos* se lee igual: los controles de corregir el vencimiento y de marcar mensual se dibujan apagados nombrando el script, todas las cuotas usan el vencimiento de Tango y no hay estimaciones. El tablero no cambia por el script |

Las dos tablas nuevas nacen con las seis columnas de auditoría, así que **no van en `sql/cashflow_auditoria_usuario.sql`**, igual que las de Tarjetas que ya cumplían.

**El día que se publica el código, el tablero SÍ se mueve, con o sin los scripts**, y es lo pedido:

- **Cuentas a Pagar Locales** deja de apilar lo vencido del cronograma en el primer día del eje: va al próximo día de pago (por defecto, 2do y 4to miércoles). Los importes de la fila no cambian; cambia la columna. Ver `README-proveedores-locales.md`.
- **Gastos Supervisoras** reparte el efectivo en los lunes del mes en vez de dos viernes.
- **Pagos con Tarjetas y Otros** deja de sumar las facturas corporativas **sin tarjeta vinculada**: hasta que se vinculen, la parte de Corporativas baja. Ver `README-pagos-tarjetas.md`.
- **Logística Local no cambia**: con su valor por defecto da exactamente lo mismo, y una prueba lo fija.

#### Después de correrlos, verificar

```sql
SELECT CLAVE, VALOR, MODULO, GRUPO FROM dbo.RO_T_CASHFLOW_PARAMETROS WHERE CLAVE LIKE 'cronograma[_]%';   -- seis filas
SELECT TIPO, VIGENTE, COUNT(*) FROM dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT GROUP BY TIPO, VIGENTE;       -- los de antes, LOGISTICA
```

Y en *Parámetros › Generales*, que la tabla de configuración muestre los tres conceptos **sin** la marca *por defecto*.

### Sin scripts, pero con orden: `feature/comex-visibilidad-saldo`

No agrega DDL. Lo que pide es **desplegar primero ProyectosXL/administracion**:
después de mover una fecha de nacionalización, `Js/Comex-fechas.js` llama a
`/administracion/comercioExterior/controller/recalcularEstimacion.php`, y si el
endpoint no existe la fecha se guarda igual pero sale el aviso de que no se
recalculó. La URL vive en `RECALCULO_ESTIMACION_URL`, al principio de ese
archivo: si en producción las dos apps no comparten host, se cambia ahí.

**El día que se despliega, Proveedores Exterior se mueve** sin que nadie cargue
nada: el padrón pasa de 70 a 348 filas, porque los contenedores con costos
cargados y saldo pendiente vuelven a la proyección. Al 01/10/2026, de esos 278,
**250 no tienen fecha estimada de pago** (no suman en ninguna columna; el aviso de
valuación los informa en dólares), 26 tienen la fecha vencida (no suman) y **2
entran al eje**, por U$S 131.705. Es la decisión: ver la sección 10 de
`README-comex.md`.

### Sin scripts: `feature/cashflow-avisos-agrupados`

No agrega ni modifica ningún script: cambia cómo se muestran los avisos del
tablero (ver *Avisos del tablero*). Se despliega solo con el código.

### Cobranzas Franquicias: el universo y la exclusión (`feature/cobranzas-fr-franquicias-exclusion`)

Contra `central`, en cualquier orden y en cualquier momento respecto del código:

| # | Script | Qué hace | Si no se corre |
| --- | --- | --- | --- |
| 1 | `sql/cashflow_cobranzas_ppp_grupo.sql` | **Modificado: hay que volver a correrlo.** La vista `RO_V_CASHFLOW_PPP_GRUPO` y su semilla pasan de `'FR%'` a `'[FL]%'`, para que los locales `L` de gestión asistida tengan PPP calculado | Los clientes `L` se listan y se proyectan igual, pero sin PPP calculado: caen al manual del grupo, a su `DIAS_PP_MAX` o a 30 |
| 2 | `sql/cashflow_cobranzas_cliente_excluido.sql` | Crea `RO_T_CASHFLOW_COBRANZAS_CLIENTE_EXCLUIDO`, la exclusión manual de clientes de Cobranzas Franquicias, con motivo e historial | Nadie está excluido, que es lo cierto. El switch de *Excluir* de Parámetros → Cobranzas queda deshabilitado con un aviso que dice qué script falta |

El filtro de franquicias habilitadas en el directorio **no necesita script**: entra con el código. Saca del tablero lo que proyectaban las franquicias inhabilitadas —medido al 07/10/2026, $ 198.465.750,18— y suma a los locales `L`. Las cifras, en `README-cobranzas-fr.md`.

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
-- 22. sql/cashflow_estructura_grupos.sql  (filas agrupadas: la cobranza FR en un renglon que se abre)
-- 23. sql/cashflow_compras_proyectadas.sql  (la parte PROYECTADA de los dos egresos de Comex)
-- 24. sql/migracion_parametros_modulo.sql  (la columna MODULO, si la tabla venia de antes)
-- 25. sql/cashflow_parametros_generales.sql  (sub-pestana Generales: inflacion y cronograma)
-- 26. sql/cashflow_logistica_fleteros.sql  (el maestro de fleteros de Logistica Local)
-- 27. sql/cashflow_tarjetas.sql  (el maestro de tarjetas y sus resumenes)
-- 28. sql/cashflow_tarjetas_facturas.sql  (vinculo factura-tarjeta y exclusion)
-- 29. sql/cashflow_tarjetas_fila.sql  (la fila Pagos con Tarjetas y Otros)
-- 30. sql/cashflow_cronograma_conceptos.sql  (un cronograma de pagos por concepto)
-- 31. sql/cashflow_tarjetas_vto_mensual.sql  (Corporativas: vencimiento corregido y facturas mensuales)
-- 32. sql/cashflow_cobranzas_ppp_grupo.sql  (Cobranzas FR: PPP por grupo empresario, clientes [FL]%)
-- 33. sql/cashflow_cobranzas_cliente_excluido.sql  (Cobranzas FR: excluir un cliente, con motivo)
```

**El 30 va después del 25**, que crea la tabla de overrides a la que le agrega `TIPO`; sin ella siembra los parámetros igual y avisa. **El 31 va después del 27** —una FK contra el maestro de tarjetas— y corta si falta.

**El 25 va después del 24**, que es el que agrega la columna `MODULO`: sin ella no hay nada que migrar y el script lo dice, pero deja el trabajo a medias. **El 26 va después del 25**, aunque no lo necesite para crearse: sin la inflación, los valores hora se proyectan sin ajuste trimestral y la pestaña avisa.

**El 28 va después del 27**, y esa sí es una dependencia dura: el vínculo factura-tarjeta tiene una FK contra el maestro, y el script corta con un mensaje si falta. **El 27 va después del 25** por el mismo motivo que el 26: sin la inflación, las estimaciones de Gastos Supervisoras y de Tarjetas Socios quedan en blanco avisando qué mes cargar. **El 29 va último** aunque no dependa técnicamente de los otros dos: es el que hace entrar la fila al tablero, y una fila activa apuntada a un proveedor sin tablas muestra un cero.

**El 13 y el 14 van en ese orden y al final**, porque el 14 mueve `SALDO_FINAL` al final de la sección que crea y da de baja la fila del saldo de inversiones que crearon los anteriores. Correr el 14 sin el 13 no rompe nada, pero deja el cuadro a medio reagrupar.

**El 18 va después del 3, del 14, de `sql/cashflow_cobertura_por_fondo.sql` y de `sql/cashflow_dolares_comitente_cobertura.sql`** (los dos de `README-otros-ingresos.md`): necesita el catálogo de cuentas, la tabla de aplicaciones con su columna `MONEDA`, y las dos filas de stock que reapunta. Si las tablas de Otros Ingresos no están, no crea las cuentas y lo dice: no hay nada que migrar, y los fondos se dan de alta desde Parámetros. Ver `README-saldos.md`.

**El 19 va después del 18**: reapunta la fila de uso que creó el 14 y necesita que exista la fila de stock de la comitente para que la fila de uso nueva tenga de dónde rescatar; si no está, avisa. Es el único cuya ausencia **puede cambiar un número**: sin él el motor cubre sólo con las inversiones, y una columna que la comitente habría tapado queda en rojo, con aviso.

**El 22 va después del 8**, que es el que parte la cobranza de franquicias en dos filas: lo que hace es declarar que esas dos son el mismo concepto. Si el 8 no se corrió, avisa, deja las tres columnas creadas y no agrupa nada. Es el único del grupo del que se puede afirmar que **no cambia ningún número por construcción y no sólo de hecho**: la agrupación es presentación y el motor no la ve. Verificado contra la base el 23/09/2026, en una transacción deshecha con `rollback`: toca 5 filas, la segunda corrida toca 0, y la estructura resultante valida sin un error ni un aviso nuevo.

**El 23 va después del 22**, que es el que agrega las tres columnas de agrupación. Si el 22 no se corrió, las dos filas nuevas **se crean igual** —sueltas, al lado de su parte real— y el script lo avisa: el tablero muestra cuatro filas en vez de dos renglones que se abren, y ningún importe cambia. Corriendo el 22 y después éste otra vez, los grupos se arman solos. Necesita además que `RO_V_COMPRA_PROYECTADA_VIGENTE` exista en `POWER_BI_CONTROL` (bloque 4 del `05_baja_logica_versiones.sql` del repo **compras**): sin esa vista las dos filas van en **cero**, y ése es el único aviso del módulo que dice que se está proyectando de menos. Ver `README-compras-proyectadas.md`.

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

Las usan **Cashflow, Ventas, Proveedores Exterior, Crono Nacionalización, Cobranzas FR, Cobranzas May, Exportaciones Tasky, Logística Local y las dos sub-pestañas de Echeqs**.

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
| `warnings` | Avisos propios de la serie: textos o `['nivel', 'texto', 'seccion']`. Un texto suelto es `warning`. Ver *Avisos del tablero* |

Los avisos del proveedor se dejan con `avisar($mensaje, $nivel, $seccion)`: el nivel es `'danger'`, `'warning'` (el defecto) o `'info'`, y el texto **no lleva el nombre de la pestaña** adelante, porque el tablero los agrupa por pestaña. `warnings()` devuelve solo los textos —lo que leen las pestañas— y `avisos()` la lista con nivel —lo que lee el motor—. El criterio de cada nivel está en *Avisos del tablero*.

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

Hoy tienen datos reales diecinueve: **Ventas**, **Cobranzas FR**, **Cobranzas Mayoristas**, **Proveedores Exterior**, **Nacionalizaciones**, **Compras Exterior** (código interno `COMPRAS_PROY`), **Saldos**, **Caja Locales**, **Cuentas de inversión**, **Cuentas comitente**, **Cobranzas Electrónicas**, **Echeqs**, **Exportaciones Tasky**, **Proveedores Locales**, **Logística**, **Pagos con Tarjetas y Otros** (código interno `TARJETAS`), **Cobertura**, y los dos de Otros Ingresos —**Dólares Cuenta Comitente** y **Saldo de Inversiones**— que están **retirados**: siguen sirviendo lo que tienen cargado, pero ya no alimentan ninguna fila. Los otros están declarados y rinden cero.

> **Logística** y **Pagos con Tarjetas y Otros** son los dos últimos en sumarse, y los dos únicos del grupo de egresos pendientes que ya tienen proveedor.
>
> Logística no necesitó ningún script de estructura: su fila ya existía apuntada a `LOGISTICA/PAGOS`, así que alcanzó con escribir el proveedor y dar vuelta el flag, que es exactamente lo que este diseño promete. Ver `README-logistica-local.md`.
>
> Tarjetas sí necesitó uno, y por un motivo que conviene tener presente: **no existía ninguna fila para esa plata**. El Excel original no la tenía, así que la semilla de `cashflow_estructura.sql` no la sembró. Lo que el diseño sigue cumpliendo es lo otro: el motor no se tocó, y la fila se crea con un `INSERT` de tres líneas. **`FINANCIERO` no se tocó** —es otro circuito, préstamos y movimientos financieros, y su fila vive en la sección *Ajustes*, hoy inhabilitada—: meter las tarjetas ahí adentro habría mezclado dos cosas que se cargan y se miran por separado. Ver `README-pagos-tarjetas.md`.

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

## Conceptos con parte real y proyectada

Hay conceptos del tablero que son **dos cosas a la vez**: una parte ya comprometida y una parte estimada. La cobranza de franquicias es el caso que existe hoy —lo aceptado en una propuesta de pago contra lo que proyecta el PPP—, y el próximo van a ser las **compras proyectadas del exterior**.

**La convención, para todo concepto futuro con parte real y proyectada:**

| | |
| --- | --- |
| En el proveedor | **Dos series**, una por parte. Nunca una sola serie que las mezcle |
| En la estructura | **Dos filas consecutivas** con el mismo `GRUPO`, cada una con su `NATURALEZA` (`REAL` / `PROYECTADO`) |
| En el tablero | **Un renglón** con la suma, que se abre y muestra las dos partes |

Las tres columnas —`GRUPO`, `NATURALEZA` y `GRUPO_NOMBRE`— las agrega `sql/cashflow_estructura_grupos.sql`, y se asignan desde *Parámetros → Cashflow*. Si el script no se corrió, el tablero funciona como antes, con una fila por parte, y lo avisa.

### La agrupación es sólo presentación

**El motor no sabe que los grupos existen.** Las filas del grupo calculan, computan y entran en su subtotal exactamente igual que si no estuvieran agrupadas; la fila agrupada la arma el front sumando columna a columna las filas que dibuja. Por eso los subtotales, el *Flujo Neto*, el *Saldo Final* y los indicadores dan lo mismo con el grupo abierto o cerrado — y hay una prueba que corre el mismo escenario dos veces, con los grupos declarados y sin declarar, y exige dos tableros idénticos campo por campo.

Si alguna vez el motor empieza a mirar `grupo`, dejó de ser presentación.

### El agrupamiento es posicional, como todo lo demás

No hay ninguna **fila padre que declare hijas**. Sería la primera referencia fila → fila del módulo, y traería de vuelta todo lo que su ausencia evita: referencias colgadas, ciclos, fórmulas que apuntan a algo que se renombró. La regla es la misma forma que usan `SUBTOTAL` y `FLUJO_NETO`:

> Filas **consecutivas**, de la **misma sección**, del **mismo tipo** y con el **mismo `GRUPO`** forman un grupo.

Consecuencias que conviene tener presentes:

- **Mover una fila con las flechas de Parámetros deshace el grupo.** Es lo mismo que pasa si se mueve un `SUBTOTAL`, y el validador lo avisa igual.
- **Una fila inhabilitada no parte nada**: no se dibuja. Hoy mismo la fila total `COBRANZAS_FR` está inactiva justo arriba de sus dos partes.
- **Un grupo de una sola fila no es un grupo**: un renglón que se abre para mostrar una fila igual a él no agrupa nada, así que se dibuja suelto.

La regla está escrita **dos veces a propósito**: `CashflowEstructura::grupos()` la corre sobre la **configuración** —todas las filas activas, para poder avisar antes de guardar— y `Js/Cashflow.js` sobre lo que **dibuja**, que no es la misma lista (las filas de stock de cobertura no se pintan). Las dos coinciden salvo que alguien le ponga un grupo a una fila que no se dibuja, y eso el validador lo rechaza. Cada lado nombra al otro y se mueven juntas.

### Qué avisa el validador, y qué rechaza

| | |
| --- | --- |
| Filas del grupo **no consecutivas**, de distinta sección o de distinto tipo | **Aviso.** El tablero las dibuja sueltas |
| Grupo **sin parte real** o **sin parte proyectada** | **Aviso.** Es válido y puede ser transitorio —una temporada sin presupuesto— |
| Dos filas del grupo declarando **nombres distintos** | **Aviso**, diciendo cuál se muestra |
| `GRUPO` en una fila **derivada o de cobertura** | **Error.** Bloquea el guardado |
| La serie **total** activa junto a sus **partes** | **Error**, el de siempre. Agrupar no habilita contar dos veces |

Los tres primeros son avisos y no errores porque **el front no depende de que estén bien**: una corrida rota se dibuja como filas sueltas, así que lo peor que puede pasar es ver dos renglones donde iba uno, nunca una suma que no corresponde. Bloquear el guardado sobre algo que no puede dar un número equivocado sería impedir el paso intermedio de cualquier reacomodamiento.

El cuarto sí es error: lo que muestra una fila derivada depende de **dónde está**, así que meterla adentro de algo que se abre y se cierra haría que el cuadro diga cosas distintas según el chevron. Desde la pantalla no puede pasar —a esas filas el editor ni les ofrece el campo, y al guardar se les vacía, igual que se le vacía el origen de datos a una fila que pasa a ser subtotal—; el error existe para lo que llegue por SQL.

### En pantalla

- Por defecto, **agrupado**. El renglón muestra el nombre del grupo, un chevron y la suma.
- Abierto, las partes van debajo **con sangría** y la etiqueta *Real* / *Proyectado*. Cada una mantiene su enlace a su pestaña, sus anotaciones y sus marcas; **la proyectada se distingue** (itálica, tono más suave), porque si al abrir las dos se vieran iguales, abrir no habría servido de nada.
- El tooltip de cada celda del renglón agrupado trae el **desglose** (`Real: $ X · Proyectado: $ Y`), y **arrastra las anotaciones de las partes**: una celda tiene un solo `title`, y con el grupo cerrado la nota de la parte proyectada —qué porción tiene fecha pactada a mano— no tendría dónde aparecer.
- **Expandir todo / Agrupar todo** en la barra, un botón para los dos gestos: si queda alguno cerrado abre todos, y si están todos abiertos los cierra. Con grupos, se muestra; sin grupos, no está.
- El estado de cada grupo se recuerda **por usuario**, en `localStorage`, con **una clave por código de grupo** y `try/catch`. Sin estado guardado, agrupado.

> **Que un grupo esté abierto se le pregunta al DOM, no a `localStorage`.** En una ventana privada el guardado falla en silencio, y preguntándole a la preferencia el chevron abriría para siempre un grupo que ya está abierto. La preferencia decide con qué estado **arranca** el renglón; lo que está pasando lo dice la pantalla.

### Lo que hubo que tocar alrededor

- **Ordenar no puede separar una suma de sus partes.** `tabla-orden.js` gana `data-orden-sigue`: una fila marcada así no se ordena, **viaja con la anterior**. No es lo mismo que un ancla, y la diferencia es la que importa: un ancla se queda **quieta** —por eso un `SUBTOTAL` cierra su sección— y una parte se **mueve**, pero nunca sola. Sin esto, ordenar por importe dejaba la parte real de un concepto debajo de otro, con la pinta de siempre.
- **Abrir y cerrar prende y apaga `display`, no saca la fila.** De eso depende que *Exportar* siga bajando lo que se ve sin que `tabla-export.js` sepa que los grupos existen: grupo cerrado, baja el renglón; abierto, bajan las tres filas.
- **La sangría y la etiqueta van en el texto de la celda**, no sólo en el CSS: Excel no interpreta un `padding`. Y la etiqueta va **adelante** del nombre, porque la columna Concepto recorta con puntos suspensivos y lo que va al final es lo primero que desaparece — justo cuando el nombre de estas filas es largo por haber tenido que distinguirse de su par.
- **Las columnas fijas y el marcado de negativos no se tocaron.** Mostrar u ocultar filas no cambia ninguna columna, y las columnas en rojo salen del payload del motor y no del DOM.

### Qué tiene las dos partes, y qué no

Relevado sobre las filas activas del tablero. Vale tenerlo escrito porque la tentación es llamar "real y proyectado" a cualquier corte de dos:

| Fila | |
| --- | --- |
| **Cobranzas Franquicias** | **Las dos.** `COBRANZA_REAL` (propuestas aceptadas) y `COBRANZA_PROYECTADA` (PPP). Es el grupo `COBRANZAS_FR` |
| **Proveedores Exterior** | **Las dos.** `COMEX_PROV_EXT → PAGOS` (los contenedores ya cargados, ubicados por sus fechas del maestro) y `COMPRAS_PROY → PAGOS_PROYECTADOS` (lo que falta comprar del presupuesto oficial). Es el grupo `PROV_EXTERIOR` |
| **Nacionalizaciones** | **Las dos.** `COMEX_NAC → NACIONALIZACION` y `COMPRAS_PROY → NACIONALIZACION_PROYECTADA`. Es el grupo `NACIONALIZACIONES` |
| Cobranzas Electrónicas | Sólo **real**: acreditaciones que la procesadora ya informó, con fecha cierta |
| Cobranzas Mayoristas | Sólo **proyectada**: facturas pendientes a +60 días. No hay circuito de propuestas de pago para mayoristas |
| Exportaciones Tasky | Sólo **proyectada**: facturas pendientes con fecha de cobro estimada |
| Las cuatro de Ventas | Todas proyectadas, pero se abren **por canal**, que es otro eje |
| Echeqs | El corte es *"¿va a entrar?"* |
| Proveedores Locales | Los cortes son por forma de pago, por rubro y por excluido |

> **Los dos grupos de Comercio Exterior son el caso que este diseño estaba esperando**, y el que muestra que el corte real/proyectado no es el único que existe. Esas dos filas **ya tenían** un corte —*"¿ya se pagó?"*, en cuatro y tres series— y ése sigue estando: es interno a la parte **real**. Lo que se agregó al lado es otra cosa, un universo que esas series no median y que **no se puede sumar a ellas**: la compra que todavía no tiene contenedor cargado. Por eso son dos proveedores y no series nuevas de `ComexProvider`. Ver `README-compras-proyectadas.md`.

Las tres de la mitad de arriba **declaran su `NATURALEZA` sin `GRUPO`**, y eso no es un descuido: una fila que es toda de una parte igual lo declara, y es lo que va a permitir después una vista de *"sólo real"* sin volver a tocar la configuración. Las de abajo no declaran nada.

---

## La sección Cobertura

El tablero proyecta el saldo día por día y en algunas columnas da negativo o queda muy justo. La plata para cubrir eso **existe** —está invertida—, y el tablero muestra cuánta hay y cuándo se usa.

Debajo del *Flujo Neto (sin cobertura)*:

| Fila | Tipo | Qué es |
| --- | --- | --- |
| Flujo Neto Acumulado (sin cobertura) | `SALDO_FINAL` | En *Resultados*, pegada al flujo neto: la posición acumulada **sin cubrir**, el rojo que dispara el rescate. Leyendo de arriba hacia abajo, es la que contesta "¿cuánto me falta?" antes de ver de dónde sale |
| Inversiones disponibles | `STOCK_COBERTURA` | **No se dibuja.** Cuánto hay en las cuentas de inversión: le da al motor el tope por fondo y el stock contra el que se calcula el disponible |
| Dólares en cuenta comitente | `STOCK_COBERTURA` | Lo mismo, para las cuentas comitente, valuado a hoy. Tampoco se dibuja |
| Uso de Inversiones | `USO_COBERTURA` | Cuánto se rescata de las cuentas de inversión en cada fecha, con **el disponible de sus fondos** en la celda de Concepto. **Lo calcula el motor** y se puede pisar a mano desde el tablero |
| Uso de Dólares comitente | `USO_COBERTURA` | Lo mismo, para las comitente, en dólares enteros vendidos a la cotización del día |
| Flujo Neto (con cobertura) | `FLUJO_NETO` | El de arriba más lo aplicado en esa columna |
| Flujo Neto Acumulado (con cobertura) | `SALDO_FINAL` | La posición proyectada, ya con la cobertura. Es la que miran los indicadores y la que pinta las columnas en rojo |

**Dos filas de saldo, y `SALDO_FINAL` arrastra sólo lo que tiene por encima.** Esto **cambió**: era *apertura de la columna (con todo) + movimientos por encima*, y con una sola fila al final da lo mismo. Con una fila de saldo arriba de la cobertura, la apertura global la volvía un híbrido —los rescates de ayer sí, el de hoy no—; ahora es la misma regla posicional de `FLUJO_NETO` aplicada al arrastre, y la fila del final sigue dando exactamente el cierre global, que el invariante verifica. Los indicadores (*Saldo Final*, *Saldo Mínimo*) y las columnas en rojo usan **la última** fila de saldo: la de arriba muestra un rojo que la cobertura ya tapó.

**Una fila de uso por clase de fondo, no una sola con un origen.** Con el motor vendiendo dólares cuando las inversiones no alcanzan, hay que ver cada cosa en su fila. El proveedor `COBERTURA` sirve `USO_INVERSION` y `USO_COMITENTE`; `APLICACION` —el total de antes— queda declarada para volver atrás y relacionada en `componentes`, así que el validador no deja activar el total y una parte a la vez. Lo crea `sql/cashflow_cobertura_automatica.sql`.

### Una fila por tipo de fondo, y es la de uso

> Esto **cambió**. La sección mostraba **cuatro** filas: dos de stock y dos de uso.

Para quien mira el tablero, *"Inversiones disponibles"* y *"Uso de Inversiones"* son dos preguntas sobre la misma plata, y contestarlas en renglones separados obliga a leer dos para saber si conviene aplicar. Ahora queda **una fila por tipo de fondo** —la de uso— con el disponible debajo del nombre:

```
Uso de Inversiones
$ 3.529.962 disponibles

Uso de Dólares comitente
$ 101.310.000 disponibles
```

**Las filas de stock siguen existiendo en `RO_T_CASHFLOW_CONF_FILA`, y tienen que seguir.** Es un cambio de **presentación**: las esconde el front, en `filaDibujable()`. Son las que le dan al motor el tope por fondo (`fondos_tope`), el stock contra el que `resolverCobertura()` calcula el disponible, y lo que `CoberturaAutomatica` respeta para no rescatar de más.

> **Desactivarlas desde Parámetros —que es lo que parece equivalente a esconderlas— rompe el cálculo entero, en silencio:** el motor se queda sin topes y sigue rescatando, pero sin límite. Es exactamente el tipo de cosa que alguien va a intentar "simplificar" el año que viene.

El criterio de aceptación del cambio fue que `test_cobertura`, `test_cobertura_automatica` y `test_fondos` siguieran pasando **sin tocarlos**: si se rompen, es que se tocó la estructura y no la presentación.

**El disponible que muestra cada fila es el de SUS fondos**, no el total. Las claves están en `fondos_fila` y el detalle en `cobertura.fondos[clave]`. El total global sumaría pesos invertidos con dólares comitente y diría, en la fila de Inversiones, plata de la que esa fila no puede rescatar. Con más de un fondo en la fila **se suman los disponibles y el tooltip los desglosa**: en pantalla lo que se decide es *"¿alcanza?"*, y para eso el número es uno solo.

**Una fila que no nombra ningún fondo no escribe la línea.** `$ 0 disponibles` diría que hay un fondo vacío en lugar de que no hay fondo.

**Se conservan los dos avisos que daba la fila de stock**: el caso **excedido** (`disponible < 0`), en rojo, que explica que se está cubriendo con plata que todavía no figura como invertida; y el detalle largo de cuánto hay invertido, cuánto aplicó el motor y cuánto se cargó a mano, ahora **por fondo** en el tooltip de la línea. El total de toda la sección sigue estando —es el único lugar donde queda desde que las filas de stock no se dibujan— y dice explícitamente que es el total, porque dos números distintos sin decir de qué es cada uno se leen como una contradicción.

**Una sola línea visible: el disponible.** La fila de uso ya tenía una segunda línea, `calculado $ X · a mano $ Y`; con el disponible agregado quedaban tres renglones en la celda de Concepto, que es lo contrario de lo que este cambio buscaba. Ese desglose **se mudó al tooltip, no se borró**: el neto que calculó el motor contra lo cargado a mano es lo que explica el número de la fila, y sin eso la fila es un importe sin causa.

**Lo que muestra la columna Total no cambia**: sigue siendo lo aplicado (`total_horizonte`). El disponible es un **stock** y va en la celda de Concepto, que además es la que queda fija al scrollear a lo ancho.

**Un fondo con stock que ninguna fila de uso aplica ahora sólo se ve por el aviso.** Antes la fila de stock estaba a la vista y su importe no cuadraba con nada; hoy, si ninguna fila lo nombra, no hay ningún renglón donde aparezca. `resolverCobertura()` ya detectaba el caso y lo avisa nombrando el script (`avisarSinFila()`), y ese aviso pasó a ser la **única** forma de enterarse.

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

**Se distingue lo calculado de lo cargado.** Cada celda de uso lleva su desglose en `cobertura_columnas` —manual y calculado, por fondo, en pesos y en la moneda del fondo— y el front lo pinta distinto: lo calculado en itálica azul, lo manual en negrita con un punto, y el `title` con el detalle (*"El motor rescata US$ 1.658 de «Cuenta comitente» ($ 2.545.030)"*). Los **totales de la fila** —*calculado* contra *a mano*, sobre todo el horizonte— los suma el motor en `cobertura_totales` y van en el tooltip de la fila; estuvieron como segunda línea en la celda de Concepto hasta que el disponible se mudó ahí. **El front no calcula ninguno.**

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

`STOCK_COBERTURA` no va en ninguna columna de fecha. Ponerlo en un día diría que ese día entra plata, y además lo sumaría el Total de esa vista como si fuera flujo. El motor le vacía las columnas y el importe queda **sólo en la columna Total**, igual en las tres vistas: lo disponible no depende del tramo que se elija mirar.

> Esa regla del motor **no cambió**, y ya no se ve: las filas de stock no se dibujan. El front tenía un `title` que explicaba por qué esas celdas iban con un guión, y se sacó junto con la fila.

Lo alimentan las series `STOCK` de `FONDO_INVERSION` y `FONDO_COMITENTE`: el **saldo a hoy** de las cuentas de inversión y comitente del catálogo de Saldos —saldo inicial + suscripciones − rescates—, calculado por `Fondos::saldoA()`. Ver `README-saldos.md`, *Pestaña 3 — Fondos*. Antes eran dos fotos cargadas en Otros Ingresos, y de cada tabla se tomaba la última: ver `README-otros-ingresos.md`, que quedó retirado.

### Cuánto queda, sin ir hasta el final de la tabla

El importe vive en la columna Total, que con veintiocho columnas queda a un scroll horizontal de distancia: ahí no lo mira nadie. Y la pregunta de quien está decidiendo dónde aplicar no es *cuánto hay* sino **cuánto queda**.

Así que la fila de uso lleva una segunda línea en su celda de **Concepto** —la columna que queda fija al scrollear a lo ancho—:

```
Uso de Inversiones  ✨
$ 3.529.962 disponibles
```

> Antes esta línea la llevaba la **fila de stock** y mostraba el disponible **global**, en formato `$ 2.529.962 de $ 3.529.962`. Las dos cosas cambiaron a la vez y por el mismo motivo: ver *Una fila por tipo de fondo*.

Va en una segunda línea y no al lado del nombre porque **el nombre sale de la configuración y puede ser largo**, y la columna tiene ancho fijo con puntos suspensivos: en la misma línea, el importe sería lo primero que se recorta.

**El motor lo calcula, el front lo dibuja.** `Cashflow::resolverCobertura()` cuelga `{stock, aplicado, disponible, hay_stock, fondos}` a las filas de la sección, después de los totales —el stock ya no está en ninguna columna a esa altura—. El front sólo **suma los fondos de la fila** y escribe. Es la regla del módulo: el front de este tablero no calcula nada.

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

Cada categoría muestra `n/m`: cuántas de sus pestañas tienen datos del sistema. Sirve para ver el avance sin abrirla, y **cuenta sólo `datos`** —una maqueta no suma—, que es lo que hace que el número sea confiable. Hoy: Ingresos 7/7, Comercio Exterior 3/3, Proveedores 1/2, y el resto en cero.

### Pestañas en desuso que se sacaron

> Esto **cambió**. El menú tenía veintiséis ítems; ahora tiene veintidós.

Se sacaron **Proveedores › Cronograma**, **RRHH y Operativos › Seguros**, **Financiero › Bopreal** y **Financiero › Pagos Div. Marzo**. Las cuatro eran el aviso de *en construcción* y nada más: sin proveedor en `CashflowRegistry`, sin SP, sin tabla, sin JS ni CSS, y sin ninguna fila en `RO_T_CASHFLOW_CONF_FILA` (verificado contra la base el 24/09/2026). Se fueron de `Class/Menu.php`, de `$validTabs` de `TabController` y del disco (`Tabs/*.php`), así que no queda ningún camino para llegar. No había datos que conservar.

Es el mismo criterio que con las dos pestañas de Otros Ingresos, con una diferencia: aquellas funcionaban y sus tablas quedaron por el histórico; éstas nunca se construyeron, así que no dejan nada atrás.

> **La pestaña *Cronograma* no era el cronograma de pagos.** El cronograma de Proveedores Locales —`FORMA_PAGO_CRONOGRAMA`, las series `PAGOS_FUERA_CRONOGRAMA` y `PAGOS_CRONO_OPERATIVOS`— vive dentro de la pestaña *Proveedores Locales* y no se tocó, igual que *Crono Nacionalización*. Ver `README-proveedores-locales.md`.

Con ellas se fue `nacionalizacion_2` de `$validTabs`, que no tenía archivo ni entrada de menú. `tests/test_menu.php` chequea que ninguna de las cinco vuelva al menú, a `TabController` ni a `Tabs/`.

**Otros Ingresos va después de Ingresos y aparte**: Ingresos agrupa lo que sale de un circuito del sistema y esa categoría agrupa lo que se tipea. La diferencia importa al leer un número — en una fila de Ingresos un cero es *"no hay movimientos"* y en una de esas es *"nadie cargó nada todavía"*. Ver `README-otros-ingresos.md`.

### Parámetros va al pie

No es un módulo de datos como los de arriba: es la configuración de todos ellos. Arriba competía por atención con el tablero, que es la pantalla que se abre para trabajar.

Los ítems de las categorías ahora tienen ícono propio, así que el sangrado de 44px que hacía de guía visual se reduce y el ícono ocupa ese lugar, alineándolos con las pestañas de nivel raíz.

---

## Administración desde Parámetros

Las sub-pestañas salen de `Parametros::$modulos`, así que **agregar un módulo es declararlo ahí y sumar su `tab-pane`**: el `<li>` se genera solo. Cada módulo tiene su propio archivo de `Tabs/`, su propio JS y un prefijo de clase propio, porque `Parametros.js` busca `.param-input`, `.mix-*` y `.respaldo-*` en **todo** el documento.

> **La primera es Generales**, y ahí viven los parámetros que afectan a todo el módulo: el horizonte —que es el eje del tablero y de las diez pantallas con eje temporal—, la alícuota, los feriados de comercio, la inflación mensual esperada y el cronograma de pagos. Estaban bajo *Ventas*, donde tocarlos parecía mover una sola pantalla. **Ningún cálculo se enteró del cambio**: el único lugar que pide parámetros por `MODULO` es esta pantalla; las fórmulas los leen por clave con `getParametrosMap()`, que no filtra. Ver `README-logistica-local.md`.
>
> Generales muestra además la **curva de dólar futuro** y la **cotización del BCRA**, las dos de **solo lectura**: llegan por API y las mantiene otro proceso. Un campo editable al lado haría creer que se pueden corregir desde ahí.

### Parámetros → Cashflow

Permite crear filas y secciones, renombrarlas, cambiarles la sección, reordenarlas, habilitarlas e inhabilitarlas.

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

### Mientras algo carga: `Js/cargando.js`

Un solo indicador para las diecinueve pestañas y para `loadTab()`, cargado una vez en `index.php` con su hoja `Css/cargando.css`. Antes había diecinueve copias del marcado `.loading-spinner` y diez del CSS, y las pestañas sin copia propia —el tablero, *Proyección*, *Proveedores Locales* y las sub-pestañas de *Parámetros*— se veían bien sólo si antes se había abierto otra cuyo CSS definía la regla sin prefijo.

```js
Cargando.mostrar(contenedor, { titulo, pasos: [...], overlay })
Cargando.paso(contenedor, indice, 'pendiente' | 'en_curso' | 'listo' | 'error')
Cargando.ocultar(contenedor)
```

- **El lugar va en el HTML de la pestaña**, vacío y con su texto: `<div class="cargando-slot" id="…" data-cargando="Cargando cheques en cartera…"></div>`. El JS sólo lo enciende y lo apaga; el título no se repite en el código.
- **Cuenta los segundos en vivo**, y a los 10 agrega *"Esto puede tardar un poco más"*. Con pedidos de 30 o 40 segundos, un círculo que gira no distingue *está trabajando* de *se colgó*.
- **Los pasos**, para las pestañas que hacen varios pedidos: *Ventas › Proyección* (venta y cobranza, en paralelo) y *Proyección › Actualizar ahora* (historia, presupuesto y grilla, en serie). Cada uno se marca cuando **su** respuesta llega.
- **`overlay: true`** va encima de contenido ya dibujado, sin sacarlo: la tabla no salta ni pierde el scroll. Lo usa *Proyección* para recargar.
- **Accesible**: `role="status"` y `aria-live="polite"`; el contador de segundos no se anuncia —cada segundo sería ruido—, el título, los pasos y la demora sí. Con `prefers-reduced-motion` el anillo no gira.

`tests/test_cargando.php` verifica que ninguna pestaña vuelva a traer su copia, que cada lugar lo encienda y lo apague el JS de su pestaña, y que nadie le toque el `display` a mano.

---

## Lo que el tablero avisa, y por qué

Los avisos no son decoración: son lo que evita leer un cero como si fuera un dato.

- Módulos todavía no construidos cuyas filas rinden cero (agrupados en un solo aviso).
- Importes que cayeron **fuera del horizonte** o **sin fecha**, con el monto.
- **Comercio Exterior ya no filtra por fecha de embarque**, y con eso se fueron los dos avisos que ese filtro necesitaba. El corte escondía **42 de 76 contenedores** —verificado el 19/09/2026—, entre ellos 10 con la fecha de pago todavía por delante: eran egresos reales informados de menos, y el aviso lo decía sin poder arreglarlo, porque la fila tampoco se veía en la pestaña. Ahora lo que decide es la fecha efectiva de cada contenedor. Ver `README-comex.md`.
  - **Lo vencido no suma, en las dos series**: al cashflow entra lo que se mueve de hoy en adelante. O ya salió —y no es proyección— o hay que corregirle la fecha, y las dos cosas son gestión sobre el dato. El aviso dice cuántos son y cuánto valen, porque si no esa plata desaparece del tablero sin que nada lo explique. **No se reubica en hoy**, a diferencia de las tres pestañas de cobranza proyectada; el porqué está en `README-comex.md`.
  - En las dos pestañas las vencidas además **se esconden por defecto**, detrás de un interruptor *Ver vencidas*, con el conteo al lado. Como ya valían cero en el período, esconderlas **no cambia ningún total**. El aviso del tablero por eso **no dice dónde están en la pantalla**: el mismo texto lo muestran el tablero y la pestaña, y afirmar "están marcadas en la grilla" sería falso donde el interruptor las tiene escondidas.
  - **Lo marcado como ya pagado sale de la proyección**, y el aviso dice cuánto. Existe precisamente porque ese importe **ya no está en la fila**: sin él, un egreso que el tablero debería proyectar desaparece y nada en pantalla lo explica. El importe no se pierde — sale por `PAGOS_PAGADOS` / `NACIONALIZACION_PAGADAS`, y el invariante con el universo cierra columna por columna.
  - **Proveedores Exterior proyecta lo que FALTA pagar**, no el FOB, y el aviso dice cuánto salió de la proyección por eso. Es el aviso que más importa de esa fila, porque describe **plata que salió sin que nadie del lado del cashflow hiciera nada**: alcanza con que alguien cargue un pago en Comercio Exterior. El tilde por lo menos lo pone una persona mirando la grilla. El importe tampoco se pierde — sale por `PAGOS_COMEX`, y desde esta rama el invariante tiene tres partes: `PAGOS + PAGOS_PAGADOS + PAGOS_COMEX = PAGOS_TODO`, con `PAGOS_TODO` valiendo el **FOB completo**, o sea lo que esa fila proyectaba antes. Ver `README-comex.md`.
  - **Un contenedor cancelado en Comercio Exterior sale del flujo solo**, sin tilde: su pendiente vale cero. El tilde manual sigue existiendo para lo que no se cargó allá.
  - **Un sobrepago se avisa en dólares, y el pendiente se toma como cero.** Nunca se proyecta un egreso negativo: sería un ingreso que nadie afirmó, y encima compensado en silencio contra el resto de la columna de ese día. Casi siempre es un pago imputado al contenedor equivocado, y se corrige en Comercio Exterior.
  - **Si no se puede leer `RO_T_IMPORTACIONES_ENCABEZADO_PAGOS`, el tablero proyecta de más** —vuelve al FOB completo— y las dos pantallas lo dicen. Es la única degradación del módulo que **cambia números** en vez de apagar un botón, y por eso su aviso va primero.
  - **Nacionalizaciones dejó de dar cero.** El aviso decía que ningún contenedor tenía gastos estimados cargados, y eso era cierto **de los 34 que el filtro dejaba pasar**: los gastos se cargan cuando el contenedor ya embarcó, así que el filtro escondía exactamente los que tenían el dato. La fila pasó de `$ 0` a `$ 561.423,77` dentro del horizonte. La guarda que avisa sigue en `ComexProvider`, por si algún día vuelve a pasar de verdad.
- **Contenedores del exterior que no se pudieron valuar**, con el conteo y **cuánto suman en dólares**. Pasa cuando no tienen fecha estimada de pago: sin fecha no hay mes, y sin mes no hay cotización que pedirle a la curva de dólar futuro. No se los convierte con ningún tipo de cambio inventado. El monto va **en dólares y no en pesos** a propósito: decirlo en pesos exigiría valuarlo, que es justamente lo que no se pudo hacer.
- **Contenedores valuados con un mes que la curva no cubre**, con el conteo y hasta dónde llega la curva. Se usa la cotización del mes más cercano y la fila queda marcada en la pestaña; aproximar en silencio sería mostrar un número que nadie puede explicar.
- **Contenedores con la cotización corregida a mano**, que manda sobre la curva.
- Falta de la curva de dólar futuro. Si no se puede leer, la fila de Proveedores Exterior va en cero con un aviso que nombra la tabla, en lugar de tumbar el tablero.
- **Exportaciones Tasky ubica en hoy las facturas cuya fecha de cobro estimada ya venció**, con el conteo y los dólares. Son facturas vencidas sin cobrar, no cobranza estimada para hoy; la celda de hoy además queda anotada con `detalle`. Sin cotización de hoy, la fila va en cero y el aviso dice cuántos dólares quedan sin valuar.
- **Cobranzas Franquicias y Mayoristas hacen lo mismo desde que la regla se unificó** (`Ingresos::ubicarCobroVencido()`, ver `README-cobranzas-fr.md`): las facturas proyectadas vencidas de hasta 180 días atrás entran en la columna de hoy, y el aviso trae el conteo y el importe. Acá va **como aviso y no como `detalle`**, a diferencia de las exportaciones: el contrato admite una anotación por celda y la celda de hoy de la cobranza proyectada ya puede tener la de la fecha pactada a mano. Dos notas por la misma celda dejarían ver una sola.

---

## Avisos del tablero

Desde `feature/cashflow-avisos-agrupados` cada aviso tiene **gravedad** y sabe **a qué pestaña pertenece**. El panel *Sobre estos números* los muestra agrupados por pestaña, contraíbles y con el color y el ícono de su nivel, con el patrón del Informe Económico (`informe_economico/Js/ie-comun.js`, `IE.pintarAvisos()`). Antes eran una lista plana de más de treinta textos, y el que decía *"el número está mal"* se veía igual que el que decía *"esto lo excluyó alguien a mano"*.

**Solo cambió el panel del tablero.** Los paneles de cada pestaña siguen recibiendo `string[]`, con los mismos textos. Ningún número, regla de cálculo ni serie cambió, y no hay scripts.

### Tres niveles, los mismos del Informe Económico

| Nivel | En pantalla | Criterio |
| --- | --- | --- |
| `danger` | Crítico | El número del tablero está mal o incompleto por algo que **no es una decisión de nadie**: un módulo que no se pudo calcular o cargar, un error de configuración, el arrastre o la cobertura que no cierran, el saldo inicial desactualizado, una fila entera en cero por falta de cotización, un script que falta y deja la fila en cero, una lectura que falló ("no se pudo leer…"), el pago contado dos veces. |
| `warning` | Atención | Plata que no entra al tablero, o entra en otro lugar, y que **se arregla con una acción**: cargar una fecha, un saldo, una cotización, vincular una factura, completar el maestro, correr un script que solo apaga la edición. |
| `info` | Informativo | Explica un criterio o algo que **alguien ya decidió**, y no pide nada: excluidos a mano, marcados como ya hechos, lo que sale por otra fila, lo que cae fuera del horizonte, los módulos sin construir. |

Un nivel mal escrito se toma como `warning` (`Aviso::nivel()`): ni `info`, que lo escondería entre los que no piden nada, ni `danger`, que abriría el panel por un error de tipeo.

Que el usuario pueda arreglar algo **no le baja la gravedad** si el número está mal y nadie lo decidió: el fletero no excluido de Proveedores Locales es crítico, y el texto dice cómo arreglarlo.

### Cómo se agrupan y ordenan

- **Grupo = la pestaña** del proveedor que emitió el aviso: el `'tab'` de `CashflowRegistry`. Los avisos propios del proveedor, los del `'warnings'` de cada serie, los descartes de la fila (fuera del horizonte / sin fecha), la falla al calcular o al cargar el módulo y el aviso de módulo retirado van con la pestaña de ese módulo.
- **Grupo "Tablero"**: los avisos del motor (estructura y validación, módulo no registrado, módulos sin construir, apertura en cero, arrastre, cobertura) y los de proveedores **sin `'tab'`** (Cobertura y los retirados de Otros Ingresos). Ahí el nombre del módulo pasa a ser la `seccion`.
- **El nombre del grupo** es el encabezado de la pestaña, `Menu::tituloTab()`: fuera del sidebar, "Proyección" sola no dice nada y "Comercio Exterior › Proyección" sí. Dos proveedores con el mismo `'tab'` (Saldos, Caja Locales y las dos clases de cuentas de fondo) caen en un solo grupo.
- **Orden de los grupos**: primero los que tienen algún crítico, después los que tienen atención, al final los que solo informan. A igual gravedad, *Tablero* primero y después en el orden del menú (`Menu::tabs()`); un grupo cuya pestaña no está en el menú va al final de su nivel.
- **Dentro de cada grupo**: de más grave a menos y, a igual gravedad, en el orden en que se emitieron.

El agrupamiento y el orden están en **una función pura del backend**, `Aviso::agrupar()`, y no en el JS: el repo prueba en PHP y no tiene corredor para JS, y el orden es una regla que tiene que poder probarse sin navegador. La respuesta del tablero trae `'avisos'` con los grupos ya ordenados —cada uno con `grupo`, `nombre`, `link`, `nivel`, `cuenta` y sus `avisos` (`nivel`, `texto`, `seccion`, `grupo`, `origen`)— y **dejó de traer `'warnings'`**, que solo leía `Js/Cashflow.js`.

### Sin prefijo, con `seccion` cuando hace falta

El texto **no repite el nombre de la pestaña**: "Proveedores Exterior: 255 contenedor(es)…" pasó a "255 contenedor(es)…". Se sacó **en el origen** —el provider o la clase—, no se recorta en pantalla.

Cuando la pestaña sola no dice de qué parte habla el aviso, el aviso lleva **`seccion`**, que el panel muestra en negrita antes del texto. Es un campo aparte y no un prefijo, porque es presentación: los paneles de cada pestaña reciben solo el texto.

| Grupo | Secciones |
| --- | --- |
| Saldos | `Saldo Inicial`, `Caja Locales`, `Inversión`, `Cuenta comitente` |
| Pagos con Tarjetas y Otros | `Supervisoras`, `Corporativas`, `Socios` |
| Cuentas a Pagar Locales | `Cronograma` |
| Comercio Exterior › Proyección | `Nacionalización` (la valuación de la fila de nacionalización) |
| Tablero | `Configuración`, `Cobertura`, o el nombre del módulo sin pestaña |

Crono Nacionalización **no lleva sección**: tiene su propia pestaña, así que "Nacionalizaciones:" sobraba.

De paso se arreglaron dos cosas que el agrupamiento dejó a la vista: los avisos de inflación de Supervisoras y Socios salían con el prefijo **duplicado** ("Tarjetas Socios: Tarjetas Socios: …", lo ponían la clase y el provider), y tres textos decían *"los avisos de arriba"*, que dejó de ser cierto al ordenar por gravedad: ahora dicen *"los otros avisos de esta pestaña"*.

Los paneles de las pestañas quedan bien sin el prefijo: ahí el usuario ya está en la pestaña. El único que pierde algo de contexto es el aviso del calendario bancario dentro del cronograma de Proveedores Locales (*"RO_T_CALENDARIO no tiene datos para 2026-12…"*), que antes decía "Cronograma de Proveedores Locales:" adelante; se entiende igual.

### El contrato

- `CashflowProvider::avisar($mensaje, $nivel = 'warning', $seccion = null)` guarda `['nivel', 'texto', 'seccion']`.
- `warnings()` sigue devolviendo **solo los textos**; ningún consumidor actual cambia. `avisos()` devuelve la lista con nivel, y es lo que lee el motor.
- `avisarTodos($lista, $nivel, $seccion)` levanta una lista entera: los textos sueltos con el nivel que se pase, los avisos con nivel con el suyo.
- El `'warnings'` de una serie acepta **textos y avisos con nivel**; un texto suelto es `warning`, así una serie vieja o armada a mano en una prueba sigue andando. `normalizar()` lo deja siempre con nivel.
- La **falla atrapada** en `series()` es crítica y **ya no empieza con el código** del módulo: en el tablero lo dicen el grupo y el `origen`.
- Las funciones de las clases que arman avisos de **niveles mezclados** devuelven la lista con nivel (`…ConNivel()`, o la clave `avisos_con_nivel`), y la versión que leen las pestañas es un envoltorio de una línea con `Aviso::textos()`. El texto se escribe una sola vez.

### Pantalla

- El alert amarillo con la X se reemplazó por un `<details>`: *Sobre estos números (N avisos)* con chips por nivel. **No hay X**: contraído sigue diciendo cuántos hay de cada nivel.
- Cada grupo es contraíble, con su nombre, su cuenta, sus chips y **"Ir a la pestaña"**, que hace clic en el `.menu-link[data-tab]` igual que el menú. Sale solo si ese ítem existe: sin permiso sobre la pestaña no hay link, pero el aviso se muestra igual porque el número lo incluye. *Tablero* no lleva link. Saldos abre su primera vista: el grupo junta varias.
- Cada aviso con el fondo, el borde y el ícono de su nivel: Font Awesome 6.4.2 free, `fa-circle-info`, `fa-triangle-exclamation` y `fa-circle-exclamation` (`fa-octagon-exclamation` es de la versión Pro). Los colores son los de `.ie-aviso-*`, copiados en variables de `Css/Cashflow.css`.
- **Qué arranca abierto**: el panel, si hay algún crítico; si no, como lo dejó el usuario. Los grupos con un crítico, siempre; el resto, como los dejó el usuario, por pestaña. Se recuerda en `localStorage` (`cashflow_avisos_abierto`, `cashflow_avisos_grupo_<tab>`) con `try/catch`.

### Todos los avisos, con su nivel

Texto resumido. "Sección" vacía es sin sección. Para revisar.

**Motor y estructura** (grupo *Tablero*, salvo donde se indica)

| Origen | Aviso | Nivel | Sección |
| --- | --- | --- | --- |
| `CashflowEstructura` | Faltan las tablas de estructura | danger | Configuración |
| `CashflowEstructura` | Faltan las columnas de agrupamiento (`GRUPO`…) | warning | Configuración |
| `Cashflow` | Error de configuración del validador | danger | Configuración |
| `Cashflow` | Módulo no registrado como origen | danger | |
| `Cashflow` | Módulo retirado (grupo: su pestaña, o Tablero) | warning | |
| `Cashflow` | No se pudo cargar el módulo (grupo: su pestaña) | danger | |
| `Cashflow` | El módulo no pudo calcularse (grupo: su pestaña) | danger | |
| `Cashflow` | Módulos todavía no construidos | info | |
| `Cashflow` | Fila de saldo en CERO: no hay saldo de apertura | danger | |
| `Cashflow` | *Fila*: importe fuera del horizonte (grupo: la pestaña de la fila) | info | |
| `Cashflow` | *Fila*: importe sin fecha (grupo: la pestaña de la fila) | warning | |
| `Cashflow` | El arrastre del saldo no cierra | danger | |
| `Cashflow` | La cobertura automática no cierra con el arrastre | danger | Cobertura |
| `Cashflow` | Lo aplicado de un fondo supera su saldo a esa fecha | warning | Cobertura |
| `Cashflow` | Se aplica de un fondo más de lo que hay | warning | Cobertura |
| `Cashflow` | Se aplica más que el total disponible | warning | Cobertura |
| `Cashflow` | Aun aplicando todo, el Saldo Final queda en rojo | warning | Cobertura |
| `Cashflow` | Fondo con stock que ninguna fila de uso aplica | warning | Cobertura |
| `CashflowProvider` | No se pudo calcular (falla atrapada) | danger | |
| todos los providers | El código de proveedor no tiene serie definida | danger | |

**Cobertura** (sin pestaña: grupo *Tablero*, sección *Cobertura*)

| Aviso | Nivel |
| --- | --- |
| Falta la tabla de aplicación de cobertura | danger |
| No se pudo leer el tipo de cambio: las aplicaciones en dólares no se muestran | danger |
| USD aplicados sin cotización anterior a su fecha | warning |
| Aplicaciones desde un origen que no es ninguna cuenta | warning |

**Otros Ingresos, retirados** (grupo *Tablero*, sección = nombre del módulo)

| Aviso | Nivel |
| --- | --- |
| No se pudo leer el tipo de cambio: fila en cero | danger |
| USD sin cotización anterior a su fecha | warning |

**Ventas**

| Aviso | Nivel |
| --- | --- |
| No se pudo leer el neteo de cheques adelantados | danger |
| Neteo: cheques que vencen después del cuadro | warning |
| Neteo: importe sin canal | warning |
| `RO_T_CALENDARIO` sin datos de un mes | warning |

**Saldos**

| Sección | Aviso | Nivel |
| --- | --- | --- |
| Saldo Inicial | Faltan las tablas del módulo | danger |
| Saldo Inicial | Ninguna cuenta tiene saldo cargado | warning |
| Saldo Inicial | N cuentas sin ningún saldo cargado | warning |
| Saldo Inicial | Saldos viejos que se muestran en la primera columna | danger |
| Saldo Inicial | US$ sin cotización del mes | warning |
| Saldo Inicial | Con qué cotización de cierre se valuó | info |
| Saldo Inicial | No se pudo leer el tipo de cambio | danger |
| Caja Locales | No se pudo leer la caja / las reservas / los saldos a mano | danger |
| Caja Locales | Local con la caja debajo de su reserva | info |
| Caja Locales | Locales sin gestión ni reserva configuradas | warning |
| Caja Locales | Locales en Envía | info |
| Caja Locales | Locales sin el saldo de ayer | warning |
| Caja Locales | Ningún local supera su reserva: fila en cero | info |
| Caja Locales | Saldo de caja anterior imputado en la primera columna | info |
| Inversión / Cuenta comitente | Faltan las cuentas de fondo | danger |
| Inversión / Cuenta comitente | Ninguna cuenta de esa clase dada de alta | warning |
| Inversión / Cuenta comitente | No se pudo leer el tipo de cambio | danger |
| Inversión / Cuenta comitente | USD sin cotización | warning |
| Inversión / Cuenta comitente | Cuentas sin saldo inicial | warning |
| Inversión / Cuenta comitente | Movimientos posteriores a hoy | info |

**Echeqs**

| Aviso | Nivel |
| --- | --- |
| Cheques excluidos a mano | info |

**Cobranzas Franquicias / Cobranzas Mayoristas**

| Aviso | Nivel |
| --- | --- |
| Facturas vencidas ubicadas en el primer día del eje | warning |
| Mayoristas: facturas con saldo pendiente negativo | warning |
| Franquicias: el directorio de sucursales no respondió y se trabaja con todas las `[FL]%` | warning |
| Franquicias: no se pudieron leer los clientes excluidos | warning |
| Franquicias: facturas de franquicias inhabilitadas, con el estado sin cargar o fuera del directorio (un aviso por caso, cliente por cliente) | info |
| Franquicias: facturas de clientes excluidos a mano | info |

**Cobranzas Electrónicas**

| Aviso | Nivel |
| --- | --- |
| Faltan las tablas del módulo | danger |
| No hay procesadoras cargadas | warning |
| Todos los movimientos ya se acreditaron: fila en cero | info |
| Procesadoras sin ningún movimiento cargado | warning |
| Acreditaciones posteriores al horizonte | info |
| Movimientos pendientes posteriores al horizonte: fila en cero | info |
| No se pudieron leer las alícuotas | danger |
| Procesadora sin alícuotas vigentes | warning |

**Exportaciones Tasky**

| Aviso | Nivel |
| --- | --- |
| Sin cotización del mes: fila en cero | danger |
| Facturas vencidas ubicadas en el primer día del eje | warning |

**Proveedores Exterior** y **Crono Nacionalización**

| Aviso | Nivel |
| --- | --- |
| No se pudo leer la curva de dólar futuro: fila en cero | danger |
| Sin fecha: no se pueden valuar | warning |
| Valuados con el mes más cercano de la curva | info |
| Cotización corregida a mano | info |
| Fecha vencida: no suman | warning |
| Fecha vencida dentro del mes en curso: sí entran | info |
| Marcados como ya hechos | info |
| Ya pagados en Comercio Exterior (Proveedores Exterior) | info |
| Sobrepago (Proveedores Exterior) | warning |
| Órdenes del mismo contenedor (Proveedores Exterior) | info |
| Falta la tabla de pagos de Comercio Exterior: proyecta de más (Proveedores Exterior) | danger |
| Contenedores sin gastos de nacionalización estimados (Crono Nacionalización) | warning |

**Comercio Exterior › Proyección**

| Aviso | Nivel |
| --- | --- |
| Falta correr el job: se proyecta de MENOS | danger |
| La última corrida del job falló: se usa el cálculo anterior | warning |
| A la historia le faltan años | warning |
| La historia arranca después de lo que pide la cuota | info |
| El presupuesto se calculó hace más de N horas | warning |
| No se pudo verificar si hay una versión oficial más nueva | danger |
| Hay una versión oficial más nueva que el cálculo | warning |
| No se pudo leer la curva de dólar futuro: fila en cero | danger |
| Falta la tabla de pagos de Comercio Exterior: se descuenta de más | danger |
| Ajuste manual apagado: falta la tabla | warning |
| La vista del presupuesto deja filas afuera | info |
| Contenedores sin fecha estimada de pago | warning |
| Órdenes de compra que no están en Tango | warning |
| Temporada sin versión oficial de presupuesto | warning |
| Sin historia de recepciones para N meses | danger |
| Ajustes manuales descartados | warning |
| Lo comprado supera lo proyectado | info |
| Valuación (los tres de Comex, arriba), también con sección *Nacionalización* | warning / info |
| No se pudieron leer los parámetros | danger |
| Faltan parámetros: se proyecta con los iniciales | warning |
| Importe fuera del horizonte | info |

**Cuentas a Pagar Locales**

| Sección | Aviso | Nivel |
| --- | --- | --- |
| | Falta la tabla de fechas de pago | danger |
| | Falta `cashflow_prov_locales_fuente_fecha.sql` | warning |
| | Falta la tabla de exclusión | warning |
| | Falta la tabla del maestro | danger |
| | Maestro vacío | warning |
| | Carga manual del maestro apagada | warning |
| | No se pudo leer CPA01 | danger |
| Cronograma | Falta configurar el cronograma / valor que no se entiende | warning |
| Cronograma | No se pudieron leer los pagos movidos a mano | danger |
| Cronograma | `RO_T_CALENDARIO` sin datos de un mes | warning |
| Cronograma | No se pudo leer el calendario bancario | danger |
| Cronograma | Falta la tabla de overrides: no se pueden editar | warning |
| Cronograma | No se pudo resolver el cronograma | danger |
| | Vencidos del cronograma sin fecha de pago | warning |
| | Vencidos fuera del cronograma sin fecha de pago | warning |
| | Sin vencimiento ni plazo | warning |
| | Proveedores con rubro excluido | info |
| | Proveedores con deuda fuera del maestro | warning |
| | La fila trae solo echeq y transferencia; el resto queda afuera | warning |
| | No se pudieron leer los vínculos con tarjetas | danger |
| | Facturas excluidas a mano | info |
| | Proveedores excluidos porque su deuda va por otra pestaña | info |

**Logística Local**

| Aviso | Nivel |
| --- | --- |
| Falta el maestro de fleteros | danger |
| Falta la tabla de inflación / no se pudo leer | danger |
| Cronograma (los de Cuentas a Pagar Locales, sin sección) | warning / danger |
| A un fletero le faltan horas, valor hora, mes base o inflación | warning |
| Meses anteriores al mes base de un fletero | warning |
| No hay fleteros activos | warning |
| Fleteros no excluidos de Proveedores Locales: se cuentan dos veces | danger |
| Falta la tabla de exclusión / no se pudo verificar: podría contarse dos veces | danger |
| Pagos posteriores al horizonte | info |
| La fila va en CERO: falta el maestro | danger |
| La fila va en CERO: sin fleteros / ninguno se pudo calcular | warning |

**Pagos con Tarjetas y Otros**

| Sección | Aviso | Nivel |
| --- | --- | --- |
| | Falta la tabla de tarjetas | danger |
| | Falta la vista de usuarios | warning |
| | No se pudo leer el maestro de bancos | danger |
| | Tarjetas de usuarios que ya no están activos | warning |
| | Tarjetas con un banco que ya no está en Tango | info |
| | No se pudo leer *X* (tarjetas, resúmenes, inflación, vínculos…) | danger |
| | Falta la tabla de inflación | danger |
| | La fila va en CERO: faltan las tablas | danger |
| | La fila va en CERO: no hay datos cargados | warning |
| Supervisoras | No se pudieron leer los gastos / el maestro / falta la tabla | danger |
| Supervisoras | Supervisoras que no se proyectan (fuera del maestro, inactivas, en cero) | warning |
| Supervisoras | Sin tarjeta de tipo Supervisora | warning |
| Supervisoras | Más de una tarjeta activa | warning |
| Supervisoras | Activas sin gastos autorizados en la ventana | info |
| Supervisoras | Falta inflación de algunos meses | warning |
| Supervisoras | Cronograma (los de Cuentas a Pagar Locales) | warning / danger |
| Supervisoras / Socios | `RO_T_CALENDARIO` sin datos de un mes | warning |
| Corporativas | No se pudieron leer las cuentas a pagar de Tango | danger |
| Corporativas | Facturas sin vincular | warning |
| Corporativas | Facturas excluidas | info |
| Corporativas | Facturas cubiertas por el resumen | info |
| Corporativas | Vinculadas a una tarjeta que ya no existe | danger |
| Corporativas | Vencimientos corregidos que ya no se aplican | warning |
| Corporativas | Estimaciones mensuales que no se proyectan | warning |
| Socios | Sin base histórica | warning |
| Socios | Base incompleta | info |
| Socios | Meses sin cotización | warning |
| Socios | Falta inflación de algunos meses | warning |
| las tres | Pagos posteriores al horizonte | info |
| las tres | Importe sin fecha | warning |

### Los que se dudaron

Quedaron con el nivel de la tabla, pero son los que vale la pena revisar:

- **No se pudo leer CPA01** (Cuentas a Pagar Locales), **el maestro de bancos** (Tarjetas) y **verificar si hay una versión oficial más nueva** (Proyección): crítico por la regla de *"no se pudo leer"*, aunque ninguno cambia un número del tablero.
- **Sin historia de recepciones para N meses** (Proyección): crítico porque esos meses no se reparten y nadie lo decidió; podría ser atención si se considera que se arregla corriendo el job.
- **La fila trae solo echeq y transferencia** (Cuentas a Pagar Locales): atención y no informativo porque una parte no entra por ninguna fila; la otra parte sí sale por otra fila, que solo sería informativo.
- **Neteo: cheques que vencen después del cuadro** (Ventas): atención; el texto dice que la cobranza queda de más por ese importe, que es un número mal.
- **Faltan parámetros de Compras Proyectadas** y **cronograma sin configurar**: atención y no crítico, porque se usan los valores por defecto, que son razonables.
- **Supervisoras activas sin gastos autorizados**: informativo, aunque es plata que el tablero no proyecta, porque autorizar gastos no es una acción de quien mira el tablero.

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

## Auditoría y permisos de escritura

**Todo lo que el módulo escribe dice quién y cuándo, y sólo puede escribirlo quien tiene permiso.** Hasta `feature/cashflow-auditoria-usuario` el usuario se grababa `NULL` en casi todas las tablas —no había login en el módulo— y cualquiera que viera una pestaña la podía editar.

### El esquema, uno solo en todas las tablas

El de Tarjetas, llevado a las 42 tablas que escribe el módulo por `sql/cashflow_auditoria_usuario.sql`:

| Columna | Qué dice |
| --- | --- |
| `USUARIO_ALTA` / `FECHA_ALTA` | Quién creó la fila y cuándo |
| `USUARIO_MODIF` / `FECHA_MODIF` | Quién la tocó por última vez y cuándo. Un alta también la llena |
| `USUARIO_BAJA` / `FECHA_BAJA` | Quién la dio de baja y cuándo. Sólo donde hay baja lógica (`VIGENTE`, `ACTIVO`, `ACTIVA`) |

- **Se graba el `username` del padrón de Gestionusuarios**, recortado a 50 caracteres. No el nombre, no el id.
- **Las fechas las pone siempre el servidor**: `GETDATE()` en el SQL, o el `DEFAULT` de la columna. Nunca vienen del navegador.
- **El front nunca manda usuario ni fecha.** El usuario sale de la sesión, en el servidor, y lo que venga en el request se ignora. Comex lo tomaba del cuerpo del request (`$data['usuario']`) para firmar el rastro de la fecha y la marca `FECHA_PAGO_CONF_USUARIO` del maestro de Comercio Exterior: eso ya no existe. El editor de la estructura, que manda su modelo entero al guardar, guarda la auditoría **fuera** del modelo por lo mismo.
- **Sin usuario resoluble, la escritura se rechaza** con 401. No hay vuelta a `$_SESSION['usuario']` ni se graba `NULL`: un nombre que no está en el padrón no tiene permisos que verificar.
- **Una baja que se deshace limpia la baja.** Reactivar una tarjeta, un fletero, un cliente de pre-chequeado o una opción deja `USUARIO_BAJA` y `FECHA_BAJA` en `NULL`: una fila activa con fecha de baja diría a la vez que está y que no está. Y en un guardado masivo —la estructura, las cuentas de Saldos— la baja se sella **sólo en la transición** de activo a inactivo: volver a guardar una fila que ya estaba de baja no cambia quién la dio de baja. Es `Auditoria::sqlBajaSegunEstado()`.

Los fragmentos SQL compartidos viven en `Class/Auditoria.php` (`SET_MODIF`, `SET_BAJA` y la baja según el estado), y **ningún método de escritura acepta `$usuario = null`**: es obligatorio, y `AuthCashflow::usuarioDeEscritura()` lanza si llega vacío. Es defensa en profundidad: el controller ya lo exigió, pero las clases se llaman también desde pruebas, sondas y procesos.

**Las filas viejas no se reconstruyen.** Lo único que se copió es lo que ya decía la tabla, y sólo donde la columna nueva estaba vacía:

- **Tablas de "última edición"** (`USUARIO` + `FECHA_UPDATE` o `FECHA_MOD`, que se pisan en cada cambio): `USUARIO → USUARIO_MODIF`, `FECHA_UPDATE`/`FECHA_MOD → FECHA_MODIF`.
- **Tablas de "una fila por carga"** (`USUARIO` + `FECHA_ALTA` + `VIGENTE`/`FECHA_BAJA`, como `COMEX_FECHA_EDIT` o `COMEX_PAGADO`): `USUARIO → USUARIO_ALTA`. `PROV_LOCALES_CATEG` además `FECHA_IMPORTACION → FECHA_ALTA`.
- Las filas de antes del script quedan con `FECHA_ALTA`/`FECHA_MODIF` en `NULL` donde no había de dónde copiar, y la pantalla dice **"sin usuario registrado"**. Como hasta ahora el usuario era `NULL` casi siempre, eso es lo que va a decir el histórico.

**`RO_T_PARAMETROS_DESC_CLIENTES` no es de este módulo**: la escribe también Tesorería (`administracion/tesoreria/cobranzas/api/parametros_controller.php`) y la leen otras tres pantallas de allá. Recibió las columnas `NULL` y **sin defaults**, para no cambiar lo que hace el otro sistema, y el cashflow **sigue escribiendo su `FECHA_MOD`**, que Tesorería usa.

### Las columnas viejas, en desuso

`USUARIO`, `FECHA_UPDATE` y `FECHA_MOD` **no se borraron**: el código dejó de leerlas y de escribirlas, y quedan con el último valor que tenían. Todas son `NULL` o tienen default, así que no bloquean ningún `INSERT` que ya no las nombra (verificado contra la base el 02/10/2026). **Su `DROP` es un script aparte, posterior al pase a producción**, que todavía no está escrito: hay que esperar a que el código nuevo esté publicado y estable, porque con el código viejo esas columnas son las que se leen. La excepción es `RO_T_PARAMETROS_DESC_CLIENTES.FECHA_MOD`, que no se borra nunca: es de Tesorería.

### Los procesos no son personas

Una escritura que no hizo una persona se firma con un **origen explícito**, nunca con `NULL` —que ya significa "fila histórica"— ni con `SUSER_SNAME()`, que devuelve la cuenta del servicio y no contesta quién:

| Caso | Qué se graba |
| --- | --- |
| Una persona lo dispara desde la pantalla: *Actualizar ahora* de Proyección, sincronizar sucursales, importar, conciliar | Su `username`. Si queda log (`RO_T_CASHFLOW_JOB_LOG`), el proceso va en `PROCESO` y la persona en `USUARIO_ALTA` |
| Un efecto en cascada de una acción de una persona: el recálculo de pendientes al guardar una alícuota, el descarte de la cotización al mover una fecha de Comex | El `username` de esa persona: es parte de su acción, y ella ve el resultado en la respuesta |
| Un efecto lateral que el código hace solo, sin acción de nadie | `SISTEMA:<Clase.metodo>`, con `AuthCashflow::origen()`. **Hoy no hay ninguno**: no hay escrituras al leer |
| Un job del SQL Agent, o un SP corrido a mano sin `@Usuario` | `JOB:<proceso>` —`JOB:COMEX_RECEP_HIST`, `JOB:COMEX_PRESUP`, `JOB:VENTAS_HIST`, `JOB:VENTAS_HIST_DIA`—, o `JOB:<nombre del SP>` si nadie lo pasó |

Los orígenes `JOB:` los arma el SQL, no el PHP, así que **la lista vive en un solo lugar**, `AuthCashflow::ORIGENES`, con el nombre con el que se muestran; `index.php` la publica para el JavaScript, y una prueba verifica que todo `'JOB:...'` de los scripts esté declarado ahí. Todo va recortado a 50 caracteres.

### Leer no es escribir: dos permisos por pestaña

Los permisos siguen viniendo de `FP_ROL_PERMISO` / `FP_PERMISOS` (módulo 7), como `cashflow.tab.<pestaña>`, y ahora hay uno más:

- `cashflow.tab.<pestaña>` deja **ver** la pestaña. No cambió.
- `cashflow.editar.<pestaña>` deja **escribir** en ella. Existe sólo para las 13 pestañas que tienen alguna escritura; las de sólo lectura —Dashboard, Exportaciones Tasky y las que todavía están en construcción— no llevan clave.
- En Parámetros la lectura sigue siendo una sola, `cashflow.tab.parametros`, y la escritura va **por sub-pestaña**: `cashflow.editar.parametros.<código en minúscula>` (`generales`, `ventas`, `saldos`, `cob_electronicos`, `prechequeado`, `cobranzas`, `compras_proy`, `logistica`, `prov_locales`, `tarjetas`, `cashflow`). Quien carga las cuentas de Saldos no necesariamente es quien toca el horizonte de todo el módulo.
- **Escribir implica leer**: una clave de edición sin la de la pestaña no habilita nada. Y el administrador (sector Proyectos con `es_admin` o `CONTROLTOTAL`) puede todo, como antes.

**La seguridad es el servidor.** Cada controller llama una vez, antes de su `switch`, a `autorizar('<Controller>', $action)` (`Controller/autorizacion.php`), que pregunta al mapa de `AuthCashflow`: sin usuario corta con **401**, sin permiso con **403**, los dos con el `{success: false, message}` de siempre. Una acción que no está en el mapa **se rechaza**: un `case` nuevo no puede quedar abierto por olvido.

**El mapa es la única fuente**: `AuthCashflow::ESCRITURAS` dice, para cada acción de cada controller, qué (pestaña, sub-pestaña) hay que poder editar, y `AuthCashflow::LECTURAS` lista las que sólo leen. Tres casos que lo explican:

- **Una acción en dos pestañas** lleva una lista, y alcanza con poder editar **cualquiera**: la fecha de Comex se mueve desde Proveedores Exterior y desde Crono Nacionalización; la fecha de cobro, desde Cobranzas FR y May; un fletero, desde Logística Local y desde Parámetros.
- **`saveParametro`**, que usan cuatro sub-pestañas, pide la sub-pestaña **del parámetro**: la saca del `MODULO` de su fila (`Parametros::subPestanaDe()`). Los generales que todavía figuran en `VENTAS/GENERAL` se editan desde Generales y piden ese permiso.
- **Los *preview* también piden edición** —previsualizar una importación, una conciliación—: no escriben, pero son el primer paso de una escritura.

**La interfaz esconde lo que no se puede usar, y es comodidad.** La raíz de cada pestaña —y en Parámetros, cada sub-pestaña— lleva `data-puede-editar`, que escribe `AuthCashflow::atributoEdicion()`. Lo que es HTML lo resuelve el PHP (`<?php if ($edita): ?>`: botones de alta, guardar, importar, conciliar, los formularios de alta); lo que arma el JS lo resuelve `Js/permisos.js` —`Permisos.puedeEditar()`, `siEdita()`, `segun()`, y `soloLectura()` para las grillas de Parámetros, que cambia cada control por su valor y saca los botones salvo los `data-lectura`, como los de historial—. Quien sólo lee ve lo mismo que antes: los datos, los filtros, exportar, ordenar y los historiales. Ocultar un botón no impide llamar al endpoint; lo que lo impide es el 403.

### Quién modificó y cuándo, en pantalla

`Js/auditoria.js` es el único lugar que convierte el esquema en texto: *"Modificado por X · dd/mm/aaaa hh:mm"*, y *"Cargado por…"* y *"Dado de baja por…"* para el alta y la baja. Un origen `JOB:` se lee *"Proceso automático (Presupuesto Comex)"*. Va como tooltip donde la celda ya existe —fechas, importes y ajustes cargados a mano: Comex, Cobranzas, Proveedores Locales, Ventas, Echeqs, Proyección, Tarjetas, Logística— y como un ícono chico en las grillas de Parámetros y los maestros. **En el tablero consolidado no hay nada**: es para leer el flujo, no para auditarlo.

### Para agregar una pestaña o una acción que escribe

1. Declarar la acción en `AuthCashflow::ESCRITURAS` con su (pestaña, sub-pestaña), o en `LECTURAS` si sólo lee. `tests/test_auth_cashflow.php` lee los `case` de los controllers y **falla si alguno no está declarado**.
2. Si es una pestaña nueva con escrituras, sumar su `cashflow.editar.<pestaña>` a `sql/cashflow_permisos_edicion.sql`, que es reejecutable. La misma prueba verifica que las claves del script sean exactamente las que pide el mapa.
3. Poner `AuthCashflow::atributoEdicion('<pestaña>')` en la raíz de la pestaña y esconder sus controles con `$edita` y `Js/permisos.js`. La prueba verifica el atributo.
4. Si la acción escribe una tabla nueva, crearla con las seis columnas y escribirlas con `Class/Auditoria.php`.

---

## Pruebas

```bash
php cashflow/tests/run.php              # todo
php cashflow/tests/run.php horizonte    # filtra por nombre de archivo
```

Cubren el eje temporal y su secuencia cronológica, las tres vistas y su criterio de columnas y totales, el validador de la estructura regla por regla, el arrastre del saldo con números conocidos, el módulo Saldos, y que un proveedor que lanza, que devuelve basura o que devuelve `null` no pueda tumbar el tablero. Las que necesitan SQL Server se saltean solas si no hay conexión.

De la **sección Cobertura**, `tests/test_cobertura.php` fija lo que la hace funcionar sin reglas nuevas en el motor: que el stock no vaya en ninguna columna de fecha y su total sea el mismo en las tres vistas; que **la posición de la fila de uso sea lo único** que separa los dos flujos netos —si alguien la mueve, esas pruebas se caen, que es exactamente lo que tienen que hacer—; que el arrastre la recoja y **el invariante `cierre[n] == apertura[n+1]` siga cerrando**; que no infle los indicadores de Ingresos ni de Egresos; y que **los subtotales anidados no dupliquen importes**, sobre un escenario con dos niveles de anidación.

Y del **saldo de cobertura**: que se mida sobre todo el horizonte y no sobre la vista —con un escenario que aplica en el tramo diario *y* en una columna mensual, donde el total del tramo diario es otro número—; que un uso negativo sume al disponible; que aplicar de más avise y no bloquee; y que sin fila de stock no se invente un disponible.

Del **panel de avisos**, `tests/test_avisos.php` fija el nivel por defecto y el inválido; que `warnings()` siga devolviendo solo textos; que el `'warnings'` de una serie acepte las dos formas; que el motor mande cada aviso a su grupo —el del proveedor, el de la serie, los descartes, el módulo que falla o no carga, el proveedor sin `'tab'` y los del motor a *Tablero*— y junte en uno a dos proveedores con la misma pestaña; el orden de grupos y de avisos; que cada envoltorio devuelva los mismos textos que su lista con nivel; y que cada `avisar()` de cada proveedor del registro diga su nivel, y —con base— que todo lo que emiten tenga un nivel válido. Las pruebas que leían la lista plana del tablero la leen con `textosTablero()` (`tests/lib.php`), y las que filtraban por el prefijo "Cobertura:" ahora filtran por la sección.

De `FLUJO_NETO`, `tests/test_cashflow.php` fija que incluya el saldo **mostrado** arriba y que no lo arrastre, y que `SALDO_FINAL` no lo cuente dos veces.

Y de la **presentación de Cobertura** —una fila por tipo de fondo—, el mismo archivo fija que el front esconda las filas de stock **filtrando antes de recorrer** (con un salteo adentro del bucle, una sección sin filas visibles igual dibujaría su encabezado y quedaría un título sin nada debajo); que el motor las siga leyendo y siga sacando de ahí los topes por fondo; que el disponible se arme con los fondos **de la fila** y no con el total; que una fila sin fondos no escriba nada; que el caso excedido se siga marcando; que el desglose *calculado / a mano* haya quedado en el tooltip y no en pantalla; y que el aviso del fondo sin fila de uso siga llegando, porque pasó a ser la única forma de enterarse.

> Que `test_cobertura`, `test_cobertura_automatica` y `test_fondos` sigan pasando **sin tocarlos** es parte del criterio: si se rompen, es que se tocó la estructura y no la presentación.

De los **fondos como cuentas**, `tests/test_cobertura.php` fija que el disponible se lleve por clave de cuenta —con un fondo sobregirado mientras el total cierra, que es el aviso que el pozo único no daba—, que un stock sin reparto y una aplicación con una clave que no es de ninguna cuenta se traten como corresponde, y que `por_fondo` **sobreviva a `series()`**, que es donde se perdió una vez. `tests/test_fondos.php` cubre el resto: ver `README-saldos.md`.

De la **cobertura automática**, `tests/test_cobertura_automatica.php` prueba primero `CoberturaAutomatica::calcular()` sola, con números, porque ahí viven todas las reglas: un día con flujo negativo pero caja de sobra **no rescata**; el rescate parcial saca exactamente lo que falta; las inversiones se agotan y recién ahí entra la comitente; los dólares se venden enteros hacia arriba (y un cociente exacto no sube uno por punto flotante; de USD 3,50 se venden 3); sin cotización no se vende; con los dos fondos agotados queda el faltante y ningún fondo va a negativo; la devolución es LIFO, acotada al sobrante del día y a lo rescatado, con una pila de varios rescates que se deshace en orden inverso; lo manual va primero, descuenta del tope, no se devuelve solo, y una devolución manual que deja rojo se cubre; el tope cambia con los movimientos previstos y un rescate previsto que se come lo usado deja el fondo sobregirado e informado; y el orden de consumo. Después lo enchufa al motor con dos filas de stock y dos de uso: que las celdas muestren manual más calculado, que el flujo con cobertura y el arrastre lo recojan sin aviso de descuadre, que el KPI y el saldo mínimo lo vean, que el desglose por columna y los totales separen los dos, que si no alcanza quede el faltante con su aviso, que un fondo sin fila de uso no se toque y se avise nombrando el script, que una fila informativa no calcule y que un stock sin tope no sea automatizable. Y lo de alrededor: `validarDisponible()` con la aplicación de la misma fecha que se pisa, la posterior que no cuenta y el negativo que pasa siempre; `Fondos::saldoProyectado()` y el tope por columna con un rescate previsto; `Cotizacion::ultimasHasta()` en dos consultas; `CoberturaProvider` con una `Cobertura` de mentira, repartiendo por clase y por columna; y el script y el registro.

De las **filas agrupadas**, `tests/test_grupos.php` cubre las dos mitades. La regla de agrupamiento —`CashflowEstructura::grupos()`, que es pura—: dos filas seguidas son un grupo; una fila en el medio lo parte y una **inhabilitada** no, que es el caso de hoy mismo; dos secciones o dos tipos tampoco son un grupo; el orden lo deciden `SECCION` y `ORDEN` y no cómo venga armado el arreglo; y el nombre lo gana la primera fila que lo declare. Después, el validador regla por regla: qué avisa (no consecutivas, sin una de las dos partes, dos nombres distintos) sin bloquear el guardado, y qué rechaza (grupo en una fila derivada o de cobertura, código inválido, naturaleza desconocida) — más que **el total junto a sus partes se sigue rechazando**, sobre el escenario del grupo, que es donde alguien podría pensar que agrupar lo reemplaza.

Y el principio de diseño entero, de la forma más dura que se puede: **el mismo escenario corrido por el motor dos veces**, con los grupos declarados y sin declarar, tiene que dar dos tableros idénticos campo por campo. Si esa prueba se cae, el motor empezó a mirar los grupos y la agrupación dejó de ser presentación.

Lo que **no** cubre es el JavaScript que arma el renglón: no hay corredor de JS. Lo que sí queda fijado es la aritmética que ese código reproduce —contra el motor de verdad, en el mismo archivo— y el cableado que se rompe callado, en `tests/test_tablas_controles.php`: que el botón de *Expandir todo* exista en la pestaña **y** esté enganchado en el JS, que las partes lleven `data-orden-sigue` **y** que `tabla-orden.js` lo entienda, que el colapso sea `display: none` —de eso depende que *Exportar* baje lo que se ve—, que los dos accesos a `localStorage` estén envueltos en `try/catch`, y que las tres condiciones de la regla estén presentes de los dos lados.

De **Comercio Exterior**, `tests/test_comex_fecha_maestra.php` fija las reglas puras de esta etapa —cuándo una fecha está vencida (con hoy inyectado, para que la prueba no caduque sola), cuándo la marca de *editada* describe el valor que se ve, y el reparto de lo vencido entre lo que entra en la columna del mes en curso y lo que queda fuera del eje— y, **leyendo archivos**, el cableado que se rompe en silencio: que el filtro por fecha de embarque no vuelva, que las columnas `EDIT` no vuelvan a leerse, que el endpoint de fechas siga siendo uno solo, que el cliente no vuelva a mandar la fecha anterior, y que el buscador y la celda de fecha no se copien en las dos pestañas. Es el mismo criterio de `test_tablas_controles.php`. Ver `README-comex.md`.

> Esas pruebas leen el código **sin sus comentarios**, y eso no es un detalle: estos archivos explican en prosa lo que dejaron de hacer —*"antes era `COALESCE(FECHA_PAGO_EDIT, ...)`"*—, que es justamente lo que este módulo pide que se escriba. Buscando el patrón sobre el archivo entero, la única forma de pasar la prueba sería borrar la explicación.

De las **compras proyectadas**, cuatro archivos y 394 comprobaciones. El grueso corre **sin base**, y ahí está el punto: en la base de hoy no se puede producir ninguno de los casos que más importan —el descuento da cero, no hay ningún ajuste cargado, y una versión oficial no se puede cambiar a voluntad para ver qué pasa—. Se fijan con datos escritos a mano: cada temporada descontando contra la fecha de **su** versión con dos cortes distintos en la misma ventana; el exceso que no se compensa y la estimación que nunca es negativa; dos meses de recepción compartiendo mes de pago y consumiendo lo cargado **una** vez; un mes sin oficial en `SIN_PRESUPUESTO` con el aviso nombrando la temporada; y el ajuste manual que se descarta al cambiar la versión, dejando intacto el de la otra temporada. Contra la base, sólo lectura: que este módulo y la pestaña *Proveedores Exterior* lean el **mismo padrón**, fila por fila y dólar por dólar. Ver `README-compras-proyectadas.md`.

> Dos de esas pruebas existen **porque ya fallaron de verdad** en la primera corrida del script: que un `PRINT` no arme su texto con una subconsulta —es error de sintaxis, así que el lote entero no corre y ningún parámetro se crea— y que los parámetros declaren `TIPO_DATO`, que es `NOT NULL`. Y el lector de SQL sin comentarios saca **sólo los de bloque**: un `--` también vive adentro de un literal, y ese script tiene un `PRINT '--- Estado ---'`.

Del **cronograma por concepto** (`feature/cronogramas-tarjetas-corporativas`), `tests/test_cronograma_pagos.php` fija quincenal y semanal para los siete días, el mes de cinco lunes, el corrimiento al hábil anterior en cualquier concepto —incluido el pago que cae en el mes anterior—, que el override de un concepto no toque a los otros, el reparto en 2, 4 y 5 partes sin redondear, el próximo pago que usa Proveedores Locales y la baja de overrides al cambiar día o frecuencia. `tests/test_logistica_planilla.php` tiene **la prueba de no regresión**: con el valor por defecto, Logística da cada pago igual que una foto sacada con el código anterior. Ver `README-logistica-local.md`, `README-proveedores-locales.md` y `README-pagos-tarjetas.md`.

De **Logística Local**, cuatro archivos que corren enteros **sin base**: el cronograma de viernes —con el mes de cinco viernes, el corrimiento **hacia atrás** (al revés que el de Ventas) y los tres feriados reales del horizonte que caen justo en un 2do o 4to viernes—, el ajuste trimestral del valor hora —incluido el **histórico real de los tres fleteros, al centavo**, y la verificación de que un ajuste suma *su mes y los dos anteriores*, probada con inflación **variable** porque con constante las tres cuentas posibles dan lo mismo—, el reparto mitad y mitad con el **mes partido por el final del tramo diario**, y el proveedor con sus tres casos de fila en cero. Ver `README-logistica-local.md`.

> Esa rama destapó dos cosas que se tapaban entre sí. `AuthCashflow` **abortaba la suite entera** con un fatal cuando `htdocs/Gestionusuarios` no estaba al lado —un checkout de este repo solo—, y con el fatal fuera del camino aparecieron **32 fallas de `test_menu`**: medía la estructura del menú contra `Menu::estructura()`, que **filtra por permisos**, y por línea de comandos no hay sesión. La estructura del menú es un hecho del código, así que ahora se pide con `Menu::estructuraCompleta()`; el filtrado por permisos tiene su propia sección, que fija que **sin sesión no se ve nada**.

De la **auditoría y los permisos de escritura**, `tests/test_auth_cashflow.php` corre entero sin sesión y sin base. Las reglas con permisos simulados —admin, ver sin editar, editar sin ver, la sub-pestaña de Parámetros—; `origen()` con su formato y su recorte; y, leyendo archivos, el cableado que se rompe en silencio: **que todo `case` de los catorce controllers esté declarado** como lectura o escritura en el mapa —agregar uno sin declararlo hace fallar la prueba—, que las claves de `sql/cashflow_permisos_edicion.sql` sean exactamente las que pide el mapa, que ningún controller tome el usuario de la sesión o del request, que ningún método de escritura tenga `$usuario = null`, que ningún SP caiga en `SUSER_SNAME()` ni grabe un origen `JOB:` que no esté en `AuthCashflow::ORIGENES`, y que cada pestaña con escrituras lleve `data-puede-editar` en su raíz y cada sub-pestaña en su pane. `Js/auditoria.js` no tiene corredor —no hay JS en la suite—; se probó con node aparte.

> Las escrituras no las ejecuta ninguna prueba: escriben en la base viva. Para esta rama se verificaron con una sonda que **compiló las 190 escrituras de 61 métodos contra `central` con `SET NOEXEC ON`** —columnas, sintaxis y cantidad de parámetros— sin escribir nada, y se confirmó después que no quedara ninguna fila de la sonda.

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
Class/Horizonte.php                         Eje temporal, compartido con Ventas
Class/EjeVista.php                          Las tres vistas: columnas, totales y periodo
Js/eje-vistas.js                            Su contraparte en el front (cargado en index.php)
Js/columnas-fijas.js                        Que columnas quedan fijas al scrollear (idem)
Js/tabla-orden.js                           Ordenar por encabezado, en las 36 tablas (idem)
Js/tabla-export.js                          Exportar a Excel lo que se ve (idem)
Js/notificaciones.js                        Avisos de accion y confirmaciones (idem)
Css/notificaciones.css
Js/cargando.js                              El indicador de carga de todas las pestanas (idem)
Css/cargando.css
Class/Menu.php                              Menu lateral y estado de cada pestana
Class/CashflowProvider.php                  Contrato de proveedor
Class/Aviso.php                             Nivel de cada aviso y el orden del panel del tablero
Class/CashflowRegistry.php                  Registro de orígenes de datos
Class/CashflowEstructura.php                Configuración: lectura, validación y CRUD
Class/Cashflow.php                          El motor
Class/Providers/VentasProvider.php
Class/Providers/ComexProvider.php
Class/Providers/IngresosProvider.php
Class/Providers/SaldosProvider.php          Disponible inicial y caja de locales
Class/Echeqs.php                            Cheques en cartera y venta cobrada anticipada
Class/Providers/EcheqsProvider.php          Las tres series de cartera: ver su encabezado
Controller/EcheqsController.php             Listados, marcado y exclusion de cheques
Tabs/echeqs.php                             Las dos sub-pestanas
Tabs/parametros_prechequeado.php            Maestro de clientes pre-chequeados
Controller/CashflowController.php           getTablero
Controller/CashflowEstructuraController.php  CRUD de la estructura
Tabs/cashflow.php                           La pantalla
Tabs/parametros_estructura.php              El editor
Js/Cashflow.js
Js/Parametros-Estructura.js
Css/Cashflow.css
Class/OtrosIngresos.php                     Otros Ingresos, retirado (README-otros-ingresos.md)
Class/Providers/OtrosIngresosProvider.php
sql/cashflow_saldo_inversiones.sql
sql/RO_V_DOLAR_OFICIAL_BCRA_DIARIO.sql      Cotización diaria, para valuar los dólares
Class/Cobertura.php                         Aplicación de inversiones para cubrir baches; los fondos son las cuentas
Class/Fondos.php                            Cuentas de inversión y comitente (README-saldos.md)
Class/Providers/FondosProvider.php Stock de cobertura por cuenta de fondo
sql/cashflow_saldos_cuentas_fondo.sql       CLASE, saldo inicial, movimientos y la migración desde Otros Ingresos
Class/Providers/CoberturaProvider.php       Lo cargado a mano, una serie por clase de fondo; no declara pestaña
sql/cashflow_compras_proyectadas.sql        Las dos filas PROYECTADAS de Comex, sus grupos, la tabla de
  ajustes y los parametros (README-compras-proyectadas.md)
Class/ComprasProyectadas.php                Temporada, cuota, ventana y la estimacion. Todo PURO
Class/ComprasProyectadasDatos.php           Las tres lecturas; no escribe nada
Class/ComprasProyectadasAjustes.php         El ajuste manual por mes, con historial
Class/Providers/ComprasProyectadasProvider.php  PAGOS_PROYECTADOS y NACIONALIZACION_PROYECTADA
Class/CoberturaAutomatica.php               El algoritmo del uso de cobertura, puro
sql/cashflow_cobertura_automatica.sql       Una fila de uso por fondo; clave fecha + fondo
Controller/CoberturaController.php          Se llama desde el tablero, no desde una pestaña
Class/Providers/ExportacionesProvider.php   Exportaciones Tasky (README-exportaciones-tasky.md)
Tabs/exportaciones_tasky.php
sql/cashflow_exportaciones_tasky.sql
Class/Comex.php                             Comercio Exterior (README-comex.md)
Js/Comex-fechas.js                          La celda editable, el tilde de pagado y el buscador, de las dos pestanas
sql/cashflow_comex_fecha_maestra.sql        La fecha vive en el maestro, con rastro de quien edito
sql/cashflow_comex_pagado.sql               Que pagos ya se hicieron: salen de la proyeccion, con historial
sql/cashflow_parametros_generales.sql       Sub-pestana Generales: migra los parametros del eje,
  crea la inflacion mensual y los overrides del
  cronograma (README-logistica-local.md)
sql/cashflow_logistica_fleteros.sql         El maestro de fleteros. No siembra ninguno
Class/Inflacion.php                         El % de cada mes y la suma de a tres, sin componer
Class/CronogramaPagos.php                   Un cronograma por concepto: día, frecuencia, corrimiento,
  override, reparto y próximo pago. PURO
Class/CronogramaDatos.php                   Su lectura: configuración, calendario bancario y overrides
sql/cashflow_cronograma_conceptos.sql       Día y frecuencia por concepto; TIPO en los overrides
Class/TarjetasFacturaVto.php                El vencimiento corregido de una cuota de Corporativas
Class/TarjetasMensual.php                   Las facturas mensuales de Corporativas
sql/cashflow_tarjetas_vto_mensual.sql       Las dos tablas de esas dos clases
Class/LogisticaValorHora.php                El ajuste trimestral del valor hora. PURO
Class/LogisticaPlanilla.php                 Importes, mitades y los cinco motivos de null. PURO
Class/Logistica.php                         El maestro de fleteros y la lectura de los insumos
Class/Providers/LogisticaProvider.php       LOGISTICA / PAGOS
Controller/LogisticaController.php La planilla y el maestro; lo usan las dos pantallas
Tabs/logistica_local.php                    La pestana (era un placeholder)
Tabs/parametros_generales.php               Sub-pestana Generales
Tabs/parametros_logistica.php               Sub-pestana Logistica: alta de fleteros contra CPA01
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

De la rama `feature/comex-saldo-pendiente`: `tests/test_comex_saldo_pendiente.php` (nuevo) · `Class/Comex.php` (**el cashflow pasa a leer `RO_T_IMPORTACIONES_ENCABEZADO_PAGOS`**, la tabla de pagos de Comercio Exterior, y sólo a leerla: `saldoPendiente()` replica la regla de `Pagos::obtenerResumen()` de ese repo, `enPesos()`, `conSaldo()`, `getPagosDelContenedor()`, `tienePagosComex()` y su aviso; el FOB y los pagos salen de la OC principal —`COALESCE(ID_PADRE, ID)`— con el corte que impide contar dos veces un contenedor con varias órdenes de compra; `valuar()` se aplica al pendiente; `avisosSaldoComex()`, `avisosSobrepago()` y `avisosGrupo()`) · `Providers/ComexProvider.php` (cuatro series en vez de tres: `PAGOS_COMEX`, y `PAGOS_TODO` pasa a valer el FOB completo) · `Class/CashflowRegistry.php` (la serie nueva y sus `componentes`) · `Controller/ComexController.php` (`getPagosContenedor`, sólo lectura; el aviso de valuación mide el pendiente) · `Tabs/proveedores_exterior.php` (tres columnas en dólares y el modal del detalle de pagos) · `Js/Comex-Proveedores_exterior.js` y su CSS (las tres celdas, las marcas de estado y los totales en dólares del pie) · `tests/test_comex_fecha_maestra.php` (el corte pasó a tener tres partes).

> **Ningún script.** No hay DDL nuevo: la tabla es de Comercio Exterior y ya existe. Lo único que cambia del lado de la base es que el cashflow ahora la lee — y si no la encontrara, lo dice y vuelve a proyectar el FOB completo.

De la rama `feature/logistica-local-parametros-generales`: `sql/cashflow_parametros_generales.sql`, `sql/cashflow_logistica_fleteros.sql`, `Class/Inflacion.php`, `Class/CronogramaPagos.php`, `Class/CronogramaDatos.php`, `Class/LogisticaValorHora.php`, `Class/LogisticaPlanilla.php`, `Class/Logistica.php`, `Providers/LogisticaProvider.php`, `Controller/LogisticaController.php`, `Tabs/parametros_generales.php`, `Tabs/parametros_logistica.php`, `Js/Logistica-Local.js`, `Js/Parametros-Generales.js`, `Js/Parametros-Logistica.js`, `Css/Logistica-Local.css` y cuatro archivos de pruebas (nuevos) · `Tabs/logistica_local.php` (era un placeholder) · `Class/Parametros.php` (los módulos `GENERALES` y `LOGISTICA`; las cuatro secciones nuevas; el rescate de los parámetros sin migrar) · `Class/CashflowRegistry.php` (`LOGISTICA` pasa a `disponible`) · `Class/Menu.php` (`logistica_local` pasa a `DATOS`; **`estructuraCompleta()`**, sin filtrar por permisos) · `Class/AuthCashflow.php` (el `require` del padrón va condicionado y **falla cerrada**; la sesión no se arranca con la salida ya emitida) · `Controller/ParametrosController.php` (inflación y cronograma) · `Tabs/parametros.php` (los dos panes nuevos; la tarjeta *Generales* se fue de Ventas) · `Js/Parametros.js` (**se fue el bloque de generales**, con su `FORMATO`, sus hints y sus helpers) · `tests/test_menu.php`, `tests/test_providers.php`, `tests/test_cargando.php`.

> **Ningún script toca la estructura del tablero.** La fila `LOGISTICA` ya existía apuntada a `LOGISTICA/PAGOS`: alcanzó con escribir el proveedor y dar vuelta el flag, que es exactamente lo que el registro promete. Ver `README-logistica-local.md`.

De la rama `feature/cashflow-auditoria-usuario`: `sql/cashflow_permisos_edicion.sql` (base `apps`), `sql/cashflow_auditoria_usuario.sql`, `Class/Auditoria.php`, `Controller/autorizacion.php`, `Js/permisos.js`, `Js/auditoria.js` y `tests/test_auth_cashflow.php` (nuevos) · `Class/AuthCashflow.php` (`username()`, `exigirUsuario()`, `puedeEditar()`, `exigirEdicion()`, `exigirAccion()`, `origen()`, `atributoEdicion()`, el mapa `ESCRITURAS` / `LECTURAS` y `ORIGENES`) · los catorce controllers (la guarda antes del `switch`; se fueron los `usuarioActual()`; `VentasController` pierde `saveMixCobro` y `saveParametro`, que no tenían llamador) · todas las clases que escriben (las seis columnas, el usuario obligatorio) · los cuatro SP del histórico y de Comex (el origen `JOB:`) · las 13 pestañas con escrituras, las 11 sub-pestañas de Parámetros y sus JS (los controles se esconden sin permiso; el quién y cuándo) · `index.php` (`permisos.js`, `auditoria.js` y los orígenes en el `<head>`) · `Css/main.css` (`.auditoria-icono`).

De la rama `feature/cronogramas-tarjetas-corporativas`: `sql/cashflow_cronograma_conceptos.sql`, `sql/cashflow_tarjetas_vto_mensual.sql`, `Class/TarjetasFacturaVto.php` y `Class/TarjetasMensual.php` (nuevos) · `Class/CronogramaPagos.php` (día y frecuencia por parámetro; `parte()`, `proximoPago()`, `requiereBaja()`) · `Class/CronogramaDatos.php` (todo por concepto; `guardarConfig()` con la baja de overrides) · `Class/Parametros.php`, `Controller/ParametrosController.php`, `Tabs/parametros_generales.php`, `Js/Parametros-Generales.js` (la card del cronograma por concepto) · `Class/Logistica.php`, `Class/LogisticaPlanilla.php`, `Js/Logistica-Local.js`, `Tabs/logistica_local.php` (el reparto y los textos salen de la configuración) · `Class/Proveedores.php`, `Controller/ProveedoresController.php`, `Providers/ProveedoresProvider.php`, `Js/Proveedores-Proveedores_locales.js`, `Tabs/proveedores_locales.php` y su CSS (los días de pago del cronograma; el aviso de `TARJETA CORP` partido) · `Class/TarjetasCorporativas.php`, `Class/PagosTarjetas.php`, `Class/TarjetasSupervisoras.php`, `Providers/TarjetasProvider.php`, `Controller/TarjetasController.php`, `Tabs/pagos_tarjetas.php`, `Js/Financiero-Pagos_tarjetas.js` y su CSS (sin vincular fuera del flujo, vencimiento corregido, estimaciones mensuales, lunes de supervisoras) · `Class/AuthCashflow.php` (seis acciones nuevas) · los tests de cronograma, logística, proveedores, providers y tarjetas, y `test_otros_ingresos.php` (se saltea sin conexión en vez de cortar la suite).

Eliminado: `Tabs/resumen.php`.

---

## Pendientes conocidos

- **Las listas que van metidas en el texto de un aviso** —los proveedores fuera del maestro, los locales sin saldo, las supervisoras sin tarjeta, los fleteros no excluidos— deberían pasar a un *Ver detalle* desplegable, como `detalle` en los avisos del Informe Económico. Quedó fuera de `feature/cashflow-avisos-agrupados`.
- **Los paneles de cada pestaña todavía no muestran el nivel**: siguen recibiendo los textos (`warnings()`, el `'avisos'` de cada clase). Adoptarlo es pasarles la lista con nivel —que ya existe en cada módulo— y dibujarla con el mismo componente del tablero.

- **Una aplicación manual no es un movimiento del fondo.** El motor y las cargas manuales son *proyección*: cuando el rescate se hace de verdad, la plata sale del fondo y entra al banco, y eso lo tienen que reflejar un `RESCATE` en Saldos → Fondos y el saldo bancario del día siguiente. Una carga manual con fecha pasada no descuenta del stock a hoy (`Fondos::saldoA()` no la conoce) ni entra al saldo proyectado (su columna ya no está en la secuencia): queda en su celda y en el "queda X de Y", nada más. Hoy hay una así en la base —`$ 4.000.000` de *Inversiones* el 18/09, más que el fondo—, y **bloquea cualquier carga manual nueva desde ese fondo** porque `validarDisponible()` la descuenta: hay que darla de baja desde el tablero (o registrar el rescate real). Qué hacer con una aplicación cuando su fecha pasa —convertirla en movimiento, darla de baja sola, dejarla— es una decisión pendiente.
- **Las columnas mensuales son un solo paso para el motor.** Un bache a mitad de un mes fuera del tramo diario que se tape solo antes de fin de mes no se ve, y uno que no se tape se rescata "en el mes", sin día. Es la resolución del eje, no del algoritmo; si hace falta más, se alarga `horizonte_dias`.
- **La cuenta comitente migró con `USD 1,00` como saldo inicial** y después se corrigió a `USD 66.000,00` desde Parámetros → Saldos (verificado el 19/09/2026); la foto de 71.000 del 16/09 sigue en `RO_T_CASHFLOW_DOLARES_COMITENTE`. Con la cobertura automática ese saldo importa: es lo que el motor vende cuando las inversiones no alcanzan.
- **El saldo de apertura ya no arranca en cero, pero depende de que alguien cargue.** El módulo Saldos existe (ver `README-saldos.md`) y alimenta *Saldo Inicial*. Mientras no haya ninguna carga, o mientras la última quede vieja, la fila va en cero o desactualizada y **el tablero lo avisa con la fecha del dato**: leer esos saldos como disponibilidad real sería un error caro.
- **Todas las filas del Excel ya tienen de dónde salir.** *Exportaciones Tasky* fue la última: la alimenta `ExportacionesProvider` con las facturas pendientes en dólares de `GVA12` (ver `README-exportaciones-tasky.md`). *Caja Locales* salió de esta lista cuando se construyó el módulo Saldos, y *Dólares Cuenta Comitente* y *Saldo de Inversiones* primero con **Otros Ingresos** y después como **cuentas de fondo de Saldos** (ver `README-saldos.md`): se cargan a mano —un saldo inicial y los movimientos—, pero por una pantalla y no por el Excel, así que siguen entrando al tablero por un proveedor como cualquier otra.
- **El neteo de cheques adelantados resta importes que ninguna fila del tablero suma.** Un cheque en cartera cierra solo: suma en *Echeqs en cartera* y resta de la cobranza de Ventas. Uno ya aplicado —depositado o endosado a un proveedor— no lo suma nadie, y se netea igual: **el neteo va por tilde y no por estado**, porque los cheques pre-chequeados están casi todos aplicados y filtrarlos dejaría el circuito sin efecto. Es una decisión tomada, no un pendiente; el pie de la sub-pestaña muestra el corte por estado para poder auditar el número. El detalle de lo verificado contra la base está en `README-ventas.md`.
- **`Ingresos::getCobranzasFR()` sigue haciendo una consulta por fila** en *Detalle Facturas*, para traer la fecha de emisión de cada comprobante. El tablero no lo sufre —usa `getCobranzasFRTotales()`— y el Resumen tampoco, que desde que no muestra esa columna se la saltea; lo paga *Detalle Facturas*, que es donde se pidió el detalle, **y el Resumen cuando hay filtro por fecha de emisión**, porque ahí esa fecha es lo que decide si la fila entra.
- **El Dashboard es una maqueta**: no tiene ninguna llamada al servidor, sus números están escritos a mano. El menú lo marca como tal. Cuando se construya de verdad, hay que pasarlo a `datos` en `Class/Menu.php`.
- **El rastro de las fechas de Comercio Exterior todavía no tiene pantalla que muestre el historial completo**: la celda muestra sólo la edición vigente en su tooltip. `getHistorialFecha` ya lo devuelve entero, con alta y baja. Ver `README-comex.md`.
- `pedir()` está duplicado en `Ingresos-Ventas.js` y `Parametros.js`. El código nuevo usa `pedirJson()` de `main.js`; sacar las dos copias viejas es un cambio aparte.
- **Algunas pestañas de datos todavía usan `alert()`.** `Js/notificaciones.js` está enchufado en toda la pestaña Parámetros, en Cobranzas FR, en Otros Ingresos y —desde la exclusión de cartera— en la sub-pestaña *Cheques en Cartera* de Echeqs; está disponible para el resto. Las dos pestañas de Comex también lo usan desde `feature/comex-nac-usd` —los nueve `alert()` que les quedaban, con la falla de carga pintada además adentro de la tabla vacía; ver `README-comex.md`—. Ventas, Saldos, Cob. Electrónicos y Cobranzas May siguen con el diálogo del navegador, y **la otra sub-pestaña de Echeqs sigue con un `confirm()` en el tildado masivo**: quedó así a propósito, porque cambiarla no es parte de la exclusión y mezclarla habría metido en esa etapa un archivo que no tiene nada que ver con ella. Es el mismo reemplazo, archivo por archivo.
- **Dos borrados físicos no dejan rastro de quién borró**: volver una factura de Cobranzas FR o May a la fecha calculada (`Ingresos::deleteFechaManual()`) y la fila de override de Proveedores Locales que queda vacía al sacarle la fecha (`Proveedores::deletePago()`, que antes firma el `UPDATE`). No queda fila donde anotarlo; pasarlos a baja lógica cambia el comportamiento y es una decisión aparte.
- **El `DROP` de las columnas viejas** (`USUARIO`, `FECHA_UPDATE`, `FECHA_MOD`) es un script que todavía no está escrito, para después de que el código nuevo esté publicado y estable. Ver *Auditoría y permisos de escritura*.
- **`Class/OtrosIngresos.php` conserva `$usuario = null` y las columnas viejas**: sus pestañas se eliminaron, sus métodos de escritura no tienen ningún endpoint y sus dos tablas no recibieron las columnas nuevas. Si alguna vez vuelven a usarse, hay que pasarlas al esquema.
- **El PPP manual por grupo y el medio de pago por cliente (Parámetros → Cobranzas) se graban con auditoría pero no la muestran**: su grilla sale de `Ingresos`, que es la misma lectura que usa el motor, y no se le sumó nada que la pueda hacer fallar.
- **Las sub-pestañas de Parámetros esconden los controles por permiso, pero los dos endpoints de Logística y de la fecha de Comex aceptan cualquiera de sus dos pestañas** (ver *Leer no es escribir*): quien edita Parámetros → Logística puede guardar un fletero aunque en Logística Local lo vea sin controles. Es lo pedido —el endpoint hace lo mismo venga de donde venga—, y se nota sólo desde la consola.
