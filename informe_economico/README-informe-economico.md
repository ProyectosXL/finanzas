# Informe Económico por Canal

Informe económico por canal y por local, con comparativo interanual, ranking de locales y corrección a mano de importes del resumen. Se alimenta de `RO_T_RESUMEN_FINAL_IE`, la tabla que genera el proceso de **Control de Gastos** (repo `administracion`, `contabilidad/controlGastos.php`).

Cinco pestañas: **IE por Canales**, **IE por Locales**, **Evolución Mensual**, **Dashboard** y **Parámetros**.

> **Todo el módulo vive en `informe_economico/`**, igual que cashflow. Lo único compartido con el resto del repo es `class/conexion.php`, `class/classEnv.php` e `images/`. Las rutas de este README son relativas a `informe_economico/`; los comandos van desde la raíz del repo.

---

## Para pasar a producción: los scripts, en orden

Todos son reejecutables y ninguno borra nada. Si no se corren, la pantalla **no falla**: avisa.

| # | Script | Base | Qué hace | Si no se corre |
| --- | --- | --- | --- | --- |
| 1 | `sql/ie_estructura.sql` | central (y `TASKY_SA`) | Crea `RO_T_IE_ESTRUCTURA_FILA` y siembra la cascada del Excel | **El informe no se puede armar** y la pantalla lo dice. Parámetros → Estructura avisa lo mismo |
| 2 | `sql/ie_resumen_edicion.sql` | central (y `TASKY_SA`) | Crea `RO_T_IE_RESUMEN_EDICION` (historial de ediciones, solo inserción, con trigger) y `RO_T_IE_RUBRO_CAT_AUDITORIA` | **Se ve todo, pero no se edita nada**: ni importes ni la categoría de los rubros. Un aviso lo dice |
| 3 | `sql/ie_umbrales.sql` | central (y `TASKY_SA`) | Crea `RO_T_IE_UMBRAL` con la semilla del Excel y `RO_T_IE_PARAMETRO` con `alerta_caida_rentabilidad_pp = 3` | **Ranking sin colores**, sin la alerta de "dos o más en rojo" ni la de caída de rentabilidad. Avisa |
| 4 | `sql/ie_permisos.sql` | apps | Alta del módulo `INFORME_ECONOMICO` en `FP_MODULOS`, el permiso de HUB, los seis permisos internos y la asignación a RESPONSABLE, DIRECCION2 y DIRECTOR | **No aparece en el HUB y solo entra el administrador** |
| 5 | `sql/ie_rubros_auditoria.sql` | central, **solo Argentina** | Crea `RO_T_IE_RUBRO_AUDITORIA` (alta, nombre, baja y reactivación de rubros; solo inserción, con trigger) | **Parámetros › Rubros se ve y la categoría se cambia, pero no se puede dar de alta, renombrar, dar de baja ni reactivar un rubro.** Los botones aparecen deshabilitados, un aviso nombra el script y el servidor rechaza igual cualquier intento |

Los scripts 1 a 3 se corren **también en la base de Uruguay (`TASKY_SA`)**, para que quede lista el día que se habilite. Hoy, con `$_SESSION['entorno'] = 'uy'`, la pantalla dice "no disponible para Uruguay" y no consulta nada (ver *Pendientes*).

El script 5 **no se corre en `TASKY_SA`**. Habilita escribir en el maestro de rubros, y en Uruguay el informe no está disponible. Sin esa tabla, la escritura queda bloqueada también por ese lado.

### Estado real al 28/09/2026: los scripts ya se corrieron en producción

Los cuatro scripts **se ejecutaron por error contra producción** durante el desarrollo, el 28/09/2026. Fue un intento de validar su sintaxis que terminó ejecutándolos. Lo que quedó:

- **central**: las cinco tablas, con la semilla de la estructura, los umbrales y el parámetro X = 3. Nadie las lee todavía.
- **apps**: el módulo `INFORME_ECONOMICO` (id 20), sus 7 permisos y la asignación a los tres roles. **El módulo se apagó con `activo = 0`** hasta que el código esté publicado.

Volver a correr los scripts no cambia nada: todo va con `IF NOT EXISTS`. Por la misma razón, `ie_permisos.sql` **no vuelve a prender el módulo**: no modifica nada que ya exista. Al publicar el código hay que prenderlo a mano:

```sql
-- base apps
UPDATE dbo.FP_MODULOS SET activo = 1 WHERE codigo = 'INFORME_ECONOMICO';
```

El script avisa con un PRINT mientras el módulo siga apagado.

El script 5 (`ie_rubros_auditoria.sql`) es posterior y **no se corrió en ninguna base**.

### La tarjeta del HUB está fuera de este repo

Tener la clave `hub.app.informe_economico` no alcanza para que la tarjeta aparezca en el HUB. Cada tarjeta está escrita a mano en `sistemas/hub/index.php`, con su propio `$puedeVer…` (ver las líneas 107-119 y la tarjeta de Cashflow cerca de la 818). Para que el Informe Económico aparezca hay que sumar su tarjeta ahí. Es otro repo y no se tocó.

### Después de correrlos, verificar

- `RO_T_IE_ESTRUCTURA_FILA` con 57 filas, y ninguna `RUBROS_DE_SECCION` cuya `SECCION` no exista en `RO_T_RUBROS_CONTABLES.CAT_RUBRO_CONTABLE`. El script lo controla y lo imprime.
- Los tres roles con los 7 permisos. El script imprime una línea por rol, y avisa si un rol no existe o está repetido. En ese caso no asigna nada, para no adivinar.

---

## Estructura del código

| Capa | Archivo | Qué resuelve |
| --- | --- | --- |
| Cálculo (puro) | `Class/Periodo.php` | Períodos `M-AAAA`, rango, año anterior, meses con resumen |
| | `Class/Canales.php` | El **único** mapeo canal ↔ sucursal, nombres por defecto, estado de un local |
| | `Class/Rubros.php` | Orden natural de `COD_RUBRO`, secciones, rubros sin sección. Reglas de alta, nombre y baja: código propuesto, validaciones y avisos. Lectura y escritura del maestro, con auditoría |
| | `Class/Formulas.php` | El catálogo cerrado de fórmulas de la cascada |
| | `Class/Cascada.php` | Índice, agregado por columna, % y variación |
| | `Class/Columnas.php` | Qué columnas tiene cada pestaña |
| | `Class/Semaforo.php` | El color de un indicador |
| | `Class/HistorialEdicion.php` | Marca de editado, pisado, deshacer, restaurar, validación del importe |
| Base | `Class/BaseIE.php` | Conexión y helpers; todo parametrizado |
| | `Class/Cotizacion.php` | TCC de cierre de cada mes de `RO_V_DOLAR_OFICIAL_BCRA` |
| | `Class/Sucursales.php` | Locales cerrados, de `SUCURSALES_LAKERS` |
| | `Class/EstructuraFilas.php`, `Class/ParametrosIE.php` | Configuración |
| | `Class/Ediciones.php` | Detalle de una celda y escritura con historial |
| Orquestación | `Class/InformeEconomico.php` | Lee una vez, arma el contexto, llama a lo puro y junta los avisos |
| Acceso | `Class/AuthInformeEconomico.php`, `Class/Menu.php` | Padrón, permisos y pestañas |
| HTTP | `Controller/*.php` | JSON y HTML de cada pestaña |
| Pantalla | `Tabs/`, `Components/`, `Js/`, `Css/` | Solo dibuja: ningún número se calcula en el navegador |

**Pensado para la migración de `rentabilidad_rubro`** (que NO se hizo): va a ser una pestaña más, con una entrada en `Menu::$pestanas`, su archivo en `Tabs/`, su JS y su permiso. El mapeo de canales, los períodos, la cotización y la conversión a USD ya viven en clases propias que esa pestaña puede usar tal cual. El CSS es el suyo, portado con prefijo `ie-`.

---

## Reglas del informe

### Datos de origen

- **Un mes se muestra solo si tiene resumen**, con el mismo criterio que `existeResumen()` de `gasto.php`: algún rubro fuera de 1.1, 1.2, 1.6 y 1.8, que son los que carga el paso 2. Un mes sin resumen se excluye entero, también su 1.1 y su 1.2: si no, inflaría el 1.3 y el IVA con ventas sin costo. Aparece en un aviso y no se puede editar. En la base hay meses así: 4-2024, 5-2024, 3-2026, 7-2026 y 8-2026.
- **Los registros repetidos se suman.** Hay varios por (PERIODO, NRO_SUCURSAL, COD_RUBRO), por ejemplo 103 / 1.6 con hasta cinco en un mes. No se deduplica nada; la edición trabaja por ID.
- **Los importes se toman tal como están en la tabla**: ya pasaron por el paso 8 (coeficiente de ajuste). *Queda por confirmar si ese importe es nominal o ajustado.*

### Canales (`Class/Canales.php`)

| NRO_SUCURSAL | Canal |
| --- | --- |
| 100 | Franquicias |
| 101 | Mayoristas |
| 102, 301, 302, 303 | Ecommerce. 102 es la histórica, que englobaba todo; después se abrió en 301 VTEX, 302 Mercado Libre y 303 ICBC |
| 103 | Otros ingresos |
| NULL o 0 | **Sin sucursal** (ver abajo) |
| el resto | Locales propios |

**Sin sucursal.** Los registros con `NRO_SUCURSAL` NULL o 0 son registros viejos que no pertenecen a ninguna sucursal. La 0 **no** es "ML FULL", aunque en `SUCURSALES_LAKERS` la 0 sea esa. Van a una columna propia, visible en IE por Canales e IE por Locales, que **no suma al Total general ni a ningún canal**. Así el total coincide con el del Excel, que no los tiene: en abr-2025 a mar-2026 son, por ejemplo, 133,4 M del rubro 7.2. Tampoco entran al ranking. Un aviso lista el importe por rubro y los meses en que aparecen.

**Nombres.** Salen de `RO_T_RESUMEN_FINAL_IE` y no de `SUCURSALES_LAKERS`, porque Franquicias, Mayoristas y Ecommerce no están ahí. Se usa el `DESC_SUCURSAL` no nulo del período más reciente del rango. Si no hay ninguno se usan valores fijos: 100 "Franquicias", 101 "Mayoristas", 102 "Ecommerce", 301 "Ecommerce VTEX", 302 "Ecommerce ML", 303 "Ecommerce ICBC" y 103 "Otros ingresos". Cualquier otra sucursal sin nombre se muestra como "Sucursal N", con un aviso.

**Cerradas.** Solo aplica a locales propios: `CANAL = 'PROPIOS'` y `HABILITADO = 0` en `SUCURSALES_LAKERS`, leída con el prefijo de locales como en cashflow.
- Un local que no está en el maestro se trata como abierto, con aviso.
- Uno con `HABILITADO` NULL también se trata como abierto, con el aviso "estado no cargado en SUCURSALES_LAKERS".
- Con el toggle apagado (el valor por defecto), los cerrados no se muestran ni suman en LOCALES. **Tampoco en el Total general**, que es la suma de los canales que se ven. Un aviso dice cuántos quedaron afuera y cuánto venden.

### La cascada (`Class/Formulas.php`)

`RO_T_IE_ESTRUCTURA_FILA` decide orden, etiqueta y visibilidad. **La cuenta vive en el código**, en un catálogo cerrado y con pruebas: una fórmula editable desde una pantalla es una fórmula que un día alguien cambia sin querer.

Todas las fórmulas se calculan siempre, aunque su fila esté oculta: ocultar es presentación. Hay tres valores posibles:
- un número;
- `null`, que se muestra "—": falta el dato o hubo una división por cero;
- "no aplica": la fila no existe en esa columna.

Nunca un 0 inventado. Una suma es `null` solo si todos sus términos lo son: un rubro sin registros no anula el total de su sección.

**Decisiones a propósito:**

- **1.3 y 1.4 existen solo en locales.** En LOCALES y en el Total general son la suma de los locales (como L7 y L8 del Excel): el 1.4 del Total es −(1.3 − 1.5 *de los locales*), no del 1.5 de todos los canales. En Franquicias, Mayoristas, Ecommerce, Otros ingresos y Sin sucursal, "no aplica".
- **Mark up y relación con IVA**: solo en locales. En el Total general y en todos los otros canales, "no aplica". **No se estima multiplicando por 1,21**: hay artículos con IVA al 10,5%, y el número sería falso con apariencia de exacto.
- **Venta sin IVA de mercadería**, la base del mark up sin IVA: 1.5 en Locales y Ecommerce, 1.6 en Mayoristas, 1.7 en Franquicias, y la suma de los tres en Otros ingresos, el Total general y Sin sucursal. **El 1.8 no entra**: es recupero de promociones, no venta de mercadería.
- **RENTABILIDAD TOTAL = resultado de explotación / base, y la base cambia según la columna**, igual que el Excel:
  - en la columna LOCALES (el subtotal de locales propios) es **1.5** (`C118 = C117/C9`);
  - en cada local, en cada canal y en el Total general es **1.9**. El Ranking toma el valor de cada local, sobre 1.9.
  
  Es una decisión explícita, no un descuido.
- **Relación arancel sobre venta en TJ = 4.1.1 / 1.9.** La etiqueta dice "TJ", pero la cuenta del Excel es sobre la venta total, y se mantuvo así.
- **RESULTADO COMERCIAL es un %**: (2 + total comercialización) / 1.9.
- **El 1.8 va en ventas y el 4.1.2 en gastos, tal como vienen.** No se netea nada.
- **Rubros sin sección.** Un rubro presente en la tabla sin fila en el maestro, o sin categoría, se muestra en el bloque "SIN SECCIÓN" al final, con un aviso que manda a Parámetros. **No suma a ningún total**: no se sabe a cuál pertenece. Se resuelve en Parámetros › Rubros: si no tiene categoría, se le asigna una; si no está en el maestro, se lo da de alta. Los rubros 1.1 a 2 tienen la categoría en NULL a propósito, y la estructura los ubica por su código.
- **Un rubro nuevo entra solo en su sección.** La fila `RUBROS_DE_SECCION` se expande en todos los rubros de su categoría, así que un rubro dado de alta con categoría aparece en su lugar, en orden natural, sin tocar la estructura, las fórmulas ni los totales. Está probado.

### Columnas de cada pestaña

- **IE por Canales**: LOCALES, Franquicias, Mayoristas, Ecommerce (102 + 301 + 302 + 303), Otros ingresos, Total general y Sin sucursal. Cada columna lleva al lado su % sobre el 1.9 de la columna. Con el comparativo suma "Año ant." y "Var %". En las filas RATIO la columna de % va vacía.
- **IE por Locales**, en este orden:
  - un local propio por columna, ordenados por número;
  - el subtotal LOCALES;
  - Franquicias y Mayoristas;
  - las aperturas de Ecommerce con datos, más el subtotal ECOMMERCE;
  - Otros ingresos, el Total general y Sin sucursal.
  
  El toggle "Mostrar %" viene apagado. El comparativo agrega solo "Var %". La columna RUBRO queda fija al scrollear a lo ancho. Es CSS `sticky` y no el selector de `cashflow/Js/columnas-fijas.js`, porque acá hay una sola columna descriptiva.
- **Evolución Mensual**: una columna por mes y el total, para el canal elegido. El tipo de columna sigue al canal, así que con el canal Locales la rentabilidad va sobre 1.5, igual que en la columna LOCALES. Con el comparativo, **cada mes muestra una sola cosa a la vez**, según el selector Actual / Año anterior / Var %; el Total muestra las tres. Con tres columnas por mes, doce meses serían casi cuarenta columnas.

### Comparativo

- Usa el mismo rango doce meses antes (`Periodo::anioAnterior`, que cruza de año solo). Los meses del año anterior sin resumen generan un aviso y quedan en `null`.
- **La variación de un importe** es (actual − anterior) / |anterior|. Se divide por el valor absoluto para que el signo diga siempre si subió o bajó, aunque el año anterior fuera una pérdida. Con base 0 o `null` da "—".
- **La variación de un ratio** es la diferencia en **puntos porcentuales** ("+1,2 pp"). Una "variación %" de un porcentaje se lee como si hubiera caído la venta.
- **Ecommerce.** Antes de 2026 todo estaba en 102, así que lo comparable es el subtotal ECOMMERCE. La variación de cada apertura se muestra igual, con una nota visible.

### Moneda

En U$S, cada importe se divide por el **TCC de cierre de su propio mes** en `RO_V_DOLAR_OFICIAL_BCRA` (la vista ya trae una fila por mes con el último día cargado). No hay un TCC único del rango: una columna de enero a marzo suma enero al cierre de enero, febrero al de febrero y marzo al de marzo. El año anterior, igual con sus meses. La conversión se hace **antes** de la cascada: dentro de un mes todo ratio queda igual que en pesos; en un rango, cada mes pesa por su valor en dólares. Está probado.

Si falta la cotización de **algún** mes, el informe se muestra en pesos con un aviso: un mes sin convertir sumado a meses en dólares es un número inventado. Acá hay una diferencia con rentabilidad_rubro, que promedia las cotizaciones de los meses que encuentra.

### Avisos que no cortan el informe

- **Costo faltante**: una sucursal vende (1.5 a 1.7) y no tiene rubro 2 en un mes. Su mark up da "—" y su resultado bruto está sobrestimado. En la base, en ene y feb-2026 **ningún local tiene costo** (el Excel sí lo tiene): conviene revisarlo en Control de Gastos.
- **Aperturas de Ecommerce con los gastos en 102**: en un mes, 102 tiene gastos (rubros 4 a 7) y no tiene venta, y alguna de 301 a 303 vende. El aviso nombra los meses. Pasa en todo 2026. No se reasigna nada.

---

## Edición de importes

Qué se puede tocar:
- Solo el **IMPORTE** de registros que ya existen en `RO_T_RESUMEN_FINAL_IE`, **siempre por ID**.
- No se crean ni se borran registros.
- No se editan las filas calculadas ni el Total general, ni los subtotales de IE por Locales: sus registros se editan desde la columna de cada local o apertura. En IE por Canales, LOCALES es un canal, así que su celda sí abre todos sus registros (cada local por cada mes).

Cómo se edita:
- Con `ie.editar`, un clic en una celda de rubro abre el modal con **un renglón por registro**; los repetidos aparecen cada uno por separado.
- El importe tiene que ser numérico, con hasta 2 decimales (acepta `1.234,56`), y el motivo es obligatorio.
- El `UPDATE` va por ID, en una transacción, **y solo si el importe sigue siendo el que vio el usuario**. Si cambió, o si la actualización no afecta exactamente una fila, no se escribe nada y se avisa.
- Después de guardar, la pestaña se recalcula sin recargar la página.

Historial y marca:
- **El historial es solo inserción** (`RO_T_IE_RESUMEN_EDICION`, con un trigger que rechaza UPDATE y DELETE). El primer movimiento de cada ID guarda como anterior el valor del proceso: el original.
- **Marca de celda editada**: alguno de sus registros tiene historial y su importe actual es el nuevo del último movimiento. Se marca también en los subtotales y el total que lo contienen. El tooltip muestra el original, el anterior inmediato y quién, cuándo y por qué lo cambió, siempre en pesos.

Deshacer y restaurar:
- **Deshacer** vuelve el registro un paso atrás. Los movimientos funcionan como una pila: EDICION y RESTAURAR apilan, DESHACER desapila. Así, dos "deshacer" retroceden dos pasos, en lugar de alternar entre los dos últimos valores.
- **Restaurar original** vuelve al valor del proceso.
- Los dos se registran como un movimiento más, con su motivo.

**Un reproceso pisa todo lo editado, y es lo esperado.** `RO_SP_REPROCESAR_RESUMEN_FINAL_IE` borra el período y lo vuelve a insertar, así que **los IDs cambian**. Se considera "pisado" un registro cuyo ID ya no existe, o cuyo importe no coincide con el último movimiento. En ese caso pasa esto:
- la marca desaparece sola;
- Deshacer y Restaurar se deshabilitan con un aviso;
- el historial queda: el modal lo muestra, de solo lectura, en "Ediciones perdidas por un reproceso", buscando por período, sucursal y rubro.

**Las ediciones impactan en todos los lectores de la tabla**: Rentabilidad por Rubro, `RO_V_RESUMEN_FINAL_IE`, `RO_V_VENTAS_BRUTAS_IE` y cualquier otro. No es una capa de ajustes del informe: se escribe en el resumen.

---

## Parámetros

- **Estructura**: orden, etiqueta, visible y activa de cada fila. Las fórmulas no se editan.
- **Rubros**: el maestro `RO_T_RUBROS_CONTABLES`, con los rubros sin sección arriba de todo. Ahí se da de alta un rubro, se le cambia el nombre o la categoría, y se lo da de baja o se lo reactiva (ver abajo).
- **Umbrales del semáforo** (`RO_T_IE_UMBRAL`): por indicador, con su sentido y las bandas verde, amarillo, naranja y rojo. Los límites se cargan en %. Un valor fuera de toda banda queda sin color.
- **Alerta**: la caída de rentabilidad, en puntos porcentuales enteros. Hoy es 3.

Ver la pestaña pide `ie.tab.parametros`; guardar, además, `ie.editar`. Cada cambio guarda usuario y fecha.

### Rubros

**El maestro es compartido.** `RO_T_RUBROS_CONTABLES` es de Control de Gastos (repo `administracion`), y lo que se cambia acá impacta en tres lugares:

| Quién | Qué usa | Qué cambia |
| --- | --- | --- |
| **Control de Gastos** | El maestro entero, **sin filtrar `ACTIVO`**: `rubroContable.php` hace `SELECT *`. Lo leen los desplegables de Gestión de Relaciones Cuenta-Rubro, Carga, Control y Consulta de Gastos | Un rubro nuevo aparece en sus desplegables. Un cambio de nombre también. **Un rubro dado de baja sigue apareciendo**, y las cuentas relacionadas le siguen imputando gastos |
| **El proceso del período** | Al clasificar, copia `COD_RUBRO` y `RUBRO_CONTABLE` a `RO_T_INTEGRAL_TANGO_2` (los SP `RO_SP_INTEGRAL*` y `update.php`), y de ahí al resumen | Los gastos ya clasificados **conservan el nombre anterior**: el cambio vale para lo que se clasifique después |
| **Rentabilidad por Rubro** | `CAT_RUBRO_CONTABLE`, en `getGastosPorCategoria` (JOIN por `COD_RUBRO`) | Un cambio de categoría mueve los dos informes. Un rubro nuevo entra en su categoría cuando tenga importes |
| **Este informe** | Nombre, categoría y `ACTIVO` | El nombre que se ve es siempre el del maestro, en todos los meses |

Al maestro no se le agregó ninguna columna. La auditoría vive aparte:
- `RO_T_IE_RUBRO_CAT_AUDITORIA` (script 2): los cambios de categoría, como hasta ahora;
- `RO_T_IE_RUBRO_AUDITORIA` (script 5): alta, nombre, baja y reactivación, con valor anterior, valor nuevo, motivo, usuario y fecha. En el alta, el valor nuevo es un JSON con código, nombre y categoría.

La columna "Último cambio" muestra el movimiento más reciente de las dos tablas.

**Alta.** El botón "Nuevo rubro" abre un modal donde se completan estos datos:
1. **Categoría** (obligatoria). Es una de las que ya existen: desde acá no se crean categorías. Se elige primero porque de ella sale el código propuesto, y el modal muestra los rubros que ya tiene, activos y dados de baja.
2. **Código** (obligatorio). Se completa solo con el propuesto, y se puede cambiar.
3. **Nombre** (obligatorio).

El **código propuesto** se arma así:
- Se toma el último rubro de la categoría en orden natural, contando también los dados de baja, y se le suma 1 al último segmento: `7.18.` → `7.19.`, `4.6.` → `4.7.`, `5.2.1.5.` → `5.2.1.6.`.
- Si ese código ya está en el maestro o en el resumen, se sigue sumando.
- **No se propone nada** en tres casos:
  - si la categoría no tiene rubros;
  - si el último tiene un solo segmento. Es el caso de Bienes de Uso, que tiene solo `6.`: sumarle 1 daría `7.`, el prefijo de Gastos de Estructura;
  - si el código se pasa de 8 caracteres.

Validaciones del código (las aplica el servidor y el modal muestra lo que contesta):
- Formato: números separados por punto, con punto final. Si falta el punto final se agrega, y se quitan los espacios. No se aceptan letras, puntos dobles, ceros a la izquierda ni segmentos en cero: `7.09.` y `7.9.` se ordenan igual y se leen igual, pero la base los toma como dos rubros distintos.
- No puede empezar con `1.` ni con `2.`: ventas y costo son fijos, y la estructura los ubica por código.
- No puede existir ya en el maestro.
- **Hasta 8 caracteres, aunque la columna admite 10.** El proceso de Control de Gastos copia el código a `RO_T_INTEGRAL_TANGO_2` (y a `_FINAL`, `_CUENTAS_2`, `_PRORRATEOS` y `_AMORTIZACIONES`), donde la columna mide 8. Un código de 9 se grabaría en el maestro y después rompería (o truncaría) el proceso del período. Hoy el más largo, `5.2.1.5.`, mide 8 justo.
- **Coherencia con la categoría.** Es un aviso que pide confirmar, no un bloqueo: salta si el código no empieza con el **prefijo común** de los rubros de la categoría:

  | Categoría | Prefijo |
  | --- | --- |
  | Gastos de Personal | `5.1.` |
  | Gastos de Ocupación | `5.2.1.` |
  | Otros Gastos Operativos | `5.3.` |
  | Gastos de Comercialización | `4.` |
  | Gastos de Estructura | `7.` |
  | Bienes de Uso | `6.` |

  Se compara contra el prefijo y no contra el primer segmento porque Personal, Ocupación y Otros empiezan todos con 5.

Validaciones del nombre:
- Es obligatorio y se le quitan los espacios de los bordes.
- **Hasta 50 caracteres, aunque la columna admite 60**, por el mismo motivo que el código.
- No acepta caracteres que una columna `VARCHAR` no puede guardar, como los emojis.
- Si otro rubro ya tiene el mismo nombre (sin distinguir mayúsculas), avisa y pide confirmar. No bloquea: hoy ya hay dos "Provision SAC", el 5.1.3. y el 7.5.

El alta va en una transacción: vuelve a verificar que el código no exista, inserta el rubro con `ACTIVO = 1` y graba la auditoría. El maestro **no tiene UNIQUE sobre `COD_RUBRO`** (su PK es un `ID` identity), así que el control lo hace el `SELECT` con `UPDLOCK, HOLDLOCK`. Ese `SELECT` bloquea también la *ausencia* del código: si dos usuarios dan de alta el mismo código a la vez, el segundo espera, lo encuentra y no se escribe nada. Después del alta la lista se recarga con el rubro resaltado.

**Rubros del resumen que no están en el maestro.** Su fila tiene el botón "Dar de alta", que abre el mismo modal:
- el código viene cargado y no se puede editar;
- el nombre sugerido es el `RUBRO_CONTABLE` del período más reciente en el resumen;
- el usuario elige la categoría.

Al darlo de alta deja de estar "sin sección" y suma en su sección.

**Cómo llega a tener importes un rubro nuevo.** El alta lo hace aparecer en el informe, en su sección, pero vacío. Los importes llegan cuando se cumplen dos pasos en Control de Gastos:
1. se le relacionan cuentas en Gestión de Relaciones Cuenta-Rubro;
2. se procesa el período.

El resumen toma el rubro según esas relaciones. La pantalla lo dice después de cada alta.

**Cambio de nombre.** El nombre es un campo editable en cada fila, con su botón de guardar, y tiene las mismas validaciones que en el alta. Antes de guardar, la pantalla pide confirmar: el nombre se usa en el informe y en los desplegables de Control de Gastos, y los gastos ya clasificados conservan el anterior. Va en una transacción, con auditoría.

**Baja y reactivación.** Se hacen con el botón "Dar de baja" o "Reactivar" de cada fila, y el motivo es obligatorio. Antes de confirmar, el modal muestra dos datos:
- cuántas cuentas tiene relacionadas;
- cuántos registros tiene en el resumen, y de qué último período.

La baja **no se bloquea** por tener relaciones o datos, y solo pone `ACTIVO = 0`: no borra nada. La reactivación vuelve a `ACTIVO = 1`. En el informe rige la regla de siempre: un rubro inactivo sin datos en el rango no se muestra; si tiene datos, se sigue mostrando y sumando. **Control de Gastos no filtra los inactivos**, y la pantalla avisa antes de confirmar la baja: el rubro sigue en sus desplegables y las cuentas relacionadas le siguen imputando gastos. Para que no reciba más importes, hay que quitar o cambiar sus relaciones allá.

Los rubros 1.x y 2 no se dan de alta, no se renombran y no se dan de baja.

**Sin el script 5**, la lista y el cambio de categoría siguen funcionando. Alta, nombre, baja y reactivación quedan deshabilitados, con un aviso que nombra `sql/ie_rubros_auditoria.sql`, y el servidor rechaza igual cualquier escritura: un cambio sin auditoría no puede pasar.

**Uruguay.** Ninguna acción escribe con `$_SESSION['entorno'] = 'uy'`, por tres barreras:
- `ieConexion()` corta antes de cualquier acción;
- `Rubros` lo vuelve a chequear antes de escribir;
- el script 5 no se corre en `TASKY_SA`, así que allá la tabla de auditoría no existe.

### Los bordes del semáforo van a la banda peor

Un valor que cae justo en un límite toma la banda **peor** de las dos que lo comparten (`Class/Semaforo.php`, con pruebas):

- **mayor es mejor** (Rentabilidad): cada banda es (desde, hasta]. 15% es amarillo, 12% es naranja y 5% es rojo.
- **menor es mejor** (los costos): cada banda es [desde, hasta). 15% de comercialización es amarillo y 20% es rojo.

**Diferencia con el Excel**: el Excel pinta con formato condicional, con "entre" inclusivo evaluado en orden, y **en los límites de rojo** se queda con la banda de al lado. Allá, 5% de rentabilidad es naranja y 20% de comercialización, 20% de personal y 18% de ocupación son amarillo; acá son rojo. En los demás límites coinciden.

---

## Acceso

- **`AuthInformeEconomico`**: el mismo mecanismo que `AuthCashflow`. Consulta el padrón de Gestionusuarios, **falla cerrada** (sin padrón, sin sesión o ante un error, no hay permiso para nada) y usa la misma regla de administrador: sector Proyectos (id 7) + `es_admin` / `CONTROLTOTAL`. Esa regla cubre al rol Control Total del subsector Desarrollo, que entra a todo sin asignarle nada.
- **El módulo se resuelve por `FP_MODULOS.codigo = 'INFORME_ECONOMICO'`** en un solo lugar (`AuthInformeEconomico::MODULO_CODIGO`). No hay ningún id escrito en el PHP.
- **Es una copia de AuthCashflow, y no código compartido.** No había forma limpia de compartirla sin tocar cashflow: el módulo 7 y el prefijo `cashflow.tab.` están escritos dentro de `init()` y `puede()`, y el estado es estático. Queda pendiente.
- Permisos: `ie.tab.canales`, `ie.tab.locales`, `ie.tab.mensual`, `ie.tab.dashboard`, `ie.tab.parametros` e `ie.editar`.
  - Sin `ie.editar`, la edición no se dibuja **y además** el controller responde 403.
  - Una pestaña sin permiso no aparece, y su HTML tampoco se sirve.

---

## Pruebas

```
php informe_economico/tests/run.php            todo
php informe_economico/tests/run.php formulas   solo los archivos que contengan "formulas"
```

Son funciones puras, sin base. Cubren:
- el mapeo canal ↔ sucursal, con 102, 301-303, NULL y 0;
- el orden natural de `COD_RUBRO` y la suma de registros repetidos;
- cada fórmula de la cascada: el signo del 1.4, el mark up por canal, el "no aplica", la base de rentabilidad por columna y las divisiones por cero;
- los subtotales LOCALES (con y sin cerradas) y ECOMMERCE, y que "Sin sucursal" no suma al total;
- los nombres de sucursal por defecto, el rango del año anterior con cruce de año y la variación con base 0 o `null`;
- que USD no cambia los ratios;
- los bordes del semáforo;
- la marca de editado, el pisado por reproceso, deshacer y restaurar;
- los rubros sin sección;
- el código propuesto de un rubro nuevo, la validación del código (formato, 1.x y 2, repetido, largo) y del nombre, el aviso de coherencia con la categoría, que los fijos no se dan de alta, ni se renombran, ni se dan de baja, y que un rubro dado de alta entra solo en su sección;
- la consistencia entre el Menu, los archivos, los permisos del script y el catálogo de fórmulas.

La suite de cashflow tiene que seguir pasando: `php cashflow/tests/run.php`.

---

## Pendientes

- **Migración de `rentabilidad_rubro`** a una pestaña de este módulo. Allá, 301-303 caen en Locales propios (su `CASE` solo conoce 100-103), y **con el mapeo de este módulo pasan a ser Ecommerce**: los números de la migración van a cambiar por eso.
- **`class/AuthPadron.php`**: sacar a un archivo compartido lo común entre `AuthCashflow` y `AuthInformeEconomico`, en otra rama.
- **Uruguay**: faltan `RO_V_DOLAR_OFICIAL_BCRA` en `TASKY_SA` y un maestro de sucursales propio. Hasta entonces la pantalla dice "no disponible para Uruguay". Los scripts 1 a 3 ya se pueden correr ahí.
- **La tarjeta del HUB** en `sistemas/hub/index.php` (otro repo), y prender el módulo con `activo = 1` al publicar.
- **Confirmar si los importes de la tabla son nominales o ajustados** (paso 8).
- **Datos para revisar en Control de Gastos** (no son del informe): el costo (rubro 2) falta en ene y feb-2026 en todos los locales y canales; y los gastos de personal de los locales (5.1.x) de abr-jun 2026 suman $ 14,5 M contra $ 839,7 M un año antes. No se movieron a otra sucursal: faltan, y el comparativo muestra una mejora de rentabilidad que no es real.
