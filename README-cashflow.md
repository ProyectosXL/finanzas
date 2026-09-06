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

## Ejecución de los scripts

En este orden, contra `central`:

```sql
-- 1. sql/cashflow_estructura.sql
-- 2. sql/cashflow_estructura_disponibilidades.sql
```

El primero crea `RO_T_CASHFLOW_CONF_SECCION` y `RO_T_CASHFLOW_CONF_FILA`, siembra la estructura y agrega el parámetro `comex_tipo_cambio_usd`.

El segundo la reorganiza en **Disponibilidades + Ventas por canal**, que es la forma del Excel original (ver más abajo). No borra nada: las filas que reemplaza quedan inhabilitadas y visibles en el editor.

Los dos son reejecutables y no pisan nada ya editado. Si no se corrieron, la pantalla **no falla**: muestra un aviso diciendo que hay que correrlos.

---

## Las tres vistas

| Vista | Qué muestra |
| --- | --- |
| **Días** | Las columnas diarias del tramo (`horizonte_dias`) |
| **Meses** | Las columnas mensuales (`horizonte_meses`) |
| **Período completo** | Las dos ramas juntas, en orden cronológico |

**Los indicadores miden exactamente las columnas que se están mirando**, y la columna Total también. Antes eran siempre del tramo diario, aunque la pantalla mostrara los meses: el número no describía nada de lo que había en pantalla.

El **Disponible Inicial** es el único que no varía: es con cuánto se arranca hoy, un hecho del presente y no del período que uno elige mirar. Por eso su tarjeta va marcada aparte.

Una trampa que la pantalla enuncia explícitamente: **la vista Meses no cubre el horizonte completo.** Las columnas mensuales acumulan sólo los días que quedan *fuera* del tramo diario, así que su total es el del tramo mensual y no el de todo. La barra debajo de los indicadores dice en cada vista qué período se está midiendo.

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
| `tipo_cambio` | Con cuál se convirtió, si se convirtió |
| `fuera_horizonte` | Importe que cayó fuera del eje |
| `sin_fecha` | Importe sin fecha utilizable |
| `warnings` | Avisos propios de la serie |

**Los importes siempre se devuelven en pesos.** La conversión la hace el proveedor y no el motor: el tipo de cambio de un pago futuro es criterio de negocio del módulo que paga, y el día que haga falta una curva por mes en lugar de un valor único, el cambio es sólo ahí.

`fuera_horizonte` no es opcional. Un tablero de consolidación que informa de menos en silencio es peor que uno que falla.

### Módulos que todavía no existen

Van igual en el registro, con `'disponible' => false` y sin clase. Una fila que los apunte se muestra **en cero** y el tablero avisa, en vez de desaparecer del cuadro: así la pantalla tiene desde el primer día la forma completa del Excel y se ve qué falta. Cuando el módulo exista, se escribe su proveedor y se da vuelta el flag; la fila ya está configurada y se llena sola.

Hoy tienen datos reales cuatro: **Ventas**, **Cobranzas FR**, **Proveedores Exterior** y **Nacionalizaciones**. Los otros doce están declarados y rinden cero.

---

## Cómo se define una fila calculada, sin lenguaje de fórmulas

No hay ninguna referencia fila a fila guardada. El alcance es **posicional**, resuelto con `(SECCION.ORDEN, FILA.ORDEN)`:

| `FILA.TIPO` | Qué suma |
| --- | --- |
| `SALDO_INICIAL` | Nada: muestra el saldo de apertura que arrastra el motor |
| `INGRESO` | Suma (+1) |
| `EGRESO` | Resta (−1) |
| `SUBTOTAL` | Las filas de movimiento **y de saldo inicial** de su sección y de las secciones hijas |
| `FLUJO_NETO` | Las filas de movimiento que estén **por encima** |
| `SALDO_FINAL` | Lo mismo, más el arrastre del saldo |

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

El cuadro original no tiene una sección "Ingresos". Tiene dos bloques:

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

Por eso la fila de saldo inicial muestra el arrastre de la columna anterior **más** lo que aporte su proveedor en ésta.

> **Por qué las filas de Ventas son la cobranza y no la venta**: en el Excel siguen el calendario bancario (los fines de semana no tienen columna y el lunes concentra el acumulado), que es el comportamiento de la cobranza con corrimiento a día hábil. Si se quisiera ver la venta, se cambia el origen de cada fila desde Parámetros: el proveedor expone las dos series por canal.

### Totales y aperturas no se mezclan

`VentasProvider` expone diez series: cobranza y venta, totales y abiertas por los cuatro canales. Las cuatro por canal suman exactamente el total.

Activar a la vez la serie total y sus componentes cuenta **dos veces** el mismo importe, y la regla de origen repetido no lo ve, porque son series distintas. El registro declara la relación en `componentes` y el validador la rechaza.

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

El motor verifica que `cierre[n] == apertura[n+1]`; si no da, deja un aviso y no una excepción.

### Dos consecuencias que se ven en pantalla

- **Una columna fuera de la secuencia devuelve `null`, no cero**, y se dibuja con un guión sobre fondo gris. Un cero en *Saldo Final* se leería como "proyectamos cero pesos de caja", que sería mentira.
- **El total de una fila de saldo no es una suma.** Sumar saldos de apertura no significa nada: el total del tramo es el saldo al cierre del tramo, y el del horizonte el saldo al final de todo.

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

## Lo que el tablero avisa, y por qué

Los avisos no son decoración: son lo que evita leer un cero como si fuera un dato.

- Módulos todavía no construidos cuyas filas rinden cero (agrupados en un solo aviso).
- Importes que cayeron **fuera del horizonte** o **sin fecha**, con el monto.
- **Comercio Exterior filtra por fecha de embarque desde hoy**, así que un pago pendiente de un contenedor *ya embarcado* no aparece en el tablero. Sin ese aviso, los egresos de Comex quedarían informados de menos en silencio.
- **Nacionalizaciones da cero** aunque haya contenedores: hoy ninguno tiene gastos estimados cargados. El aviso trae el conteo, para distinguir "no hay datos" de "los datos son cero". La pestaña Crono Nacionalización muestra el mismo cero.
- Falta del tipo de cambio.

---

## Relación con Ventas

`COBROS_VENTAS` (proyectada) y `COBRANZAS_FR` (real) **no se pisan**: Ventas proyecta cobranza de ventas *futuras* y Cobranzas FR trae cobranza de facturas *ya emitidas*. Se suman a propósito.

```
Cobranza total = cobranza real (facturas emitidas) + cobranza sobre ventas estimadas
```

El invariante está enunciado en el encabezado de `Class/Ventas.php`.

---

## Pruebas

```bash
php tests/run.php              # todo
php tests/run.php horizonte    # filtra por nombre de archivo
```

Cubren el eje temporal y su secuencia cronológica, el validador de la estructura regla por regla, el arrastre del saldo con números conocidos, y que un proveedor que lanza, que devuelve basura o que devuelve `null` no pueda tumbar el tablero. Las que necesitan SQL Server se saltean solas si no hay conexión.

---

## Archivos

```
sql/cashflow_estructura.sql                 Las dos tablas de configuración + semilla
sql/cashflow_estructura_disponibilidades.sql  Reorganiza en Disponibilidades + Ventas
cashflow/Class/Horizonte.php                Eje temporal, compartido con Ventas
cashflow/Class/CashflowProvider.php         Contrato de proveedor
cashflow/Class/CashflowRegistry.php         Registro de orígenes de datos
cashflow/Class/CashflowEstructura.php       Configuración: lectura, validación y CRUD
cashflow/Class/Cashflow.php                 El motor
cashflow/Class/Providers/VentasProvider.php
cashflow/Class/Providers/ComexProvider.php
cashflow/Class/Providers/IngresosProvider.php
cashflow/Controller/CashflowController.php            getTablero
cashflow/Controller/CashflowEstructuraController.php  CRUD de la estructura
cashflow/Tabs/cashflow.php                  La pantalla
cashflow/Tabs/parametros_estructura.php     El editor
cashflow/Js/Cashflow.js
cashflow/Js/Parametros-Estructura.js
cashflow/Css/Cashflow.css
tests/                                      Arnés de pruebas
```

Modificados: `Class/Ventas.php` (delega el eje y acepta uno inyectado) · `Class/Ingresos.php` (`getCobranzasFRTotales`) · `Class/Parametros.php` (registro del módulo) · `Tabs/parametros.php` (navegación generada) · `Js/Parametros.js` (guardas contra null) · `Js/main.js` (`pedirJson`) · `index.php`, `TabController.php`, `Components/sidebar.php`, `Components/header.php` (el reemplazo de Resumen).

Eliminado: `Tabs/resumen.php`.

---

## Pendientes conocidos

- **El disponible inicial arranca en cero.** El módulo Saldos no existe, así que la fila *Saldo Inicial* no tiene de dónde tomar el dinero que hay hoy en los bancos. Lo que muestra es el **arrastre**: la caja que se va acumulando con los ingresos proyectados. Las filas de arrastre llevan un ícono que lo aclara y el tablero lo avisa arriba, porque leer esos saldos como disponibilidad real sería un error caro.
- **Tres filas del Excel no tienen de dónde salir.** *Dólares Cuenta Comitente*, *Exportaciones* y *Caja Locales* las tipea una persona en el Excel (Tesorería, Silvina, Dan). Acá el origen de datos es únicamente por proveedor, así que hasta que exista el módulo que las alimente se muestran en cero y el tablero avisa. Quedan declaradas para que el cuadro tenga la forma completa. Si hicieran falta cargadas a mano, habría que sumar un tipo de origen manual, que hoy el módulo no tiene.
- **El neteo de cheques adelantados sólo se aplica a la serie total de cobranza.** No viene abierto por canal. Hoy da lo mismo porque es cero; cuando exista el origen habrá que decidir cómo se distribuye entre canales, y ese criterio es de negocio.
- **`Ingresos::getCobranzasFR()` sigue haciendo una consulta por fila** para traer la razón social. El tablero no lo sufre, porque usa `getCobranzasFRTotales()`, pero la pestaña Cobranzas FR sí.
- **`VentasController?action=saveMixCobro` puede grabar un mix que Parámetros rechazaría**: no valida el 100%. Es anterior a este trabajo.
- `pedir()` está duplicado en `Ingresos-Ventas.js` y `Parametros.js`. El código nuevo usa `pedirJson()` de `main.js`; sacar las dos copias viejas es un cambio aparte.
- Sin login: todo se graba con `USUARIO = NULL`. La costura ya está puesta.
