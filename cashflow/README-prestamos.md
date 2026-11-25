# Módulo Préstamos — los préstamos bancarios, su cronograma y cuánto haría falta pedir

Pestaña **Financiero → Préstamos** (sub-pestañas *Préstamos*, *Cronograma*, *Proyección* y *Simulador*), la sub-pestaña **Parámetros › Préstamos**, la sección **Financiamiento** del tablero (proveedor `FINANCIERO`, reusado) y el interruptor **Incluir simulaciones** del tablero.

Rama: `feature/prestamos`. Hoy la empresa no tiene préstamos: la pestaña sirve para registrar los que se tomen, simular los que todavía no se pidieron y decir cuánto haría falta pedir.

---

## La idea en una línea

**Cada préstamo se calcula cuota por cuota con su TNA por días exactos / 365 (fija, o TAMAR / BADLAR + spread), en francés, alemán o bullet, con gracia, costos y cancelaciones; la lista de pagos que sale va igual a la pestaña y a la sección Financiamiento del tablero, donde lo de hoy es real, lo que viene es proyectado y lo simulado se ve sólo con el interruptor.**

```
Lo que se carga (cashflow, central)         Parámetros › Préstamos        XL-APPS.sistemas (SÓLO SE LEE)
  RO_T_CASHFLOW_PRESTAMO + costos, tramos     costos, comisión, tasas         FP_BCRA_VARIABLES_MONETARIAS
  correcciones de cuota, cancelaciones        manuales, simulador, margen     FP_BCRA_VARIABLES_LOG
DolarFuturo (curva ROFEX)   Calendario bancario   Saldos (cuentas bancarias, informativo)
        │                          │                     │
        └───────────────┬──────────┴─────────────────────┘
                        ▼
             PrestamosCalculo::proyectar()  ── LA regla, pura: cronograma + lista de pagos
                        │                     (DESEMBOLSO · CAPITAL · INTERESES, real / proyectado / simulado)
                        ├──► pestaña (Prestamos::pestana(): agrupa, no calcula)
                        └──► PrestamosProvider: *_REAL + *_PROY + *_SIM, por día ──► tablero (Financiamiento)
```

**El cashflow no escribe en `[XL-APPS].sistemas`.** Sólo lee las tablas del BCRA, y lo fija una prueba (`PrestamosTasas` no tiene sentencias de escritura).

---

## Ejecución de los scripts

| # | Script | Base | Qué hace | Si no se corre |
| --- | --- | --- | --- | --- |
| 70 | `sql/cashflow_prestamos.sql` | `central` | Las nueve tablas (parámetros generales, costos asociados, tramos, tasa manual, préstamo, costos y tramos copiados, correcciones por cuota y cancelaciones) con auditoría e historial. Siembra los 11 generales y los 5 costos de la especificación (3 activos) | La pestaña y Parámetros **cargan** y avisan en crítico nombrando el script; no se carga nada y el tablero suma cero. El resto del cashflow, como hoy |
| 71 | `sql/cashflow_prestamos_permisos.sql` | `apps` | `cashflow.tab.prestamos`, `cashflow.editar.prestamos` y `cashflow.editar.parametros.prestamos`, si no existen. **No asigna ningún rol** | Sólo el administrador ve la pestaña y edita |
| 72 | `sql/cashflow_prestamos_fila.sql` | `central` | Rehace el CHECK de `NATURALEZA` con `SIMULADO`; crea la sección **Financiamiento** (ORDEN 85, entre Egresos y Resultados), nueve filas en tres grupos y el subtotal, en una transacción, con el control de doble conteo al pie | No hay sección Financiamiento: los préstamos no entran al tablero (la pestaña funciona igual) |

**Orden: 70 → 71 → 72.** El 70 y el 72 contra `central` (el 72 después de `sql/cashflow_estructura_grupos.sql`); el 71 contra `apps`, en cualquier momento.

**El tablero se mueve con el código y con el 72**: sin préstamos vigentes cargados, la sección suma cero.

### Después de correrlos

1. Dar `cashflow.tab.prestamos` y `cashflow.editar.prestamos` a Silvia y Dan, y `cashflow.editar.parametros.prestamos` a quien corresponda, desde el hub.
2. Revisar en *Parámetros › Préstamos* los costos sembrados y la comisión de cancelación.

---

## 1. Parámetros › Préstamos

`Class/PrestamosParametros.php` (lectura, escritura y validadores puros), `Tabs/parametros_prestamos.php`, `Js/Parametros-Prestamos.js`. Permiso propio: `cashflow.editar.parametros.prestamos`; la lectura es la de Parámetros.

**Los valores de Parámetros son los generales.** Al dar de alta un préstamo se le copian los costos activos, la comisión de cancelación y sus tramos (`PrestamosParametros::copiaParaAlta()`); en el préstamo se cambia sólo lo que su contrato tenga distinto. **Un cambio posterior en Parámetros no toca a los préstamos que ya existen**: viven en sus propias tablas (`RO_T_CASHFLOW_PRESTAMO_COSTO` / `_TRAMO` y las columnas `CANCEL_*`).

| Bloque | Qué guarda |
| --- | --- |
| Tasas de referencia | La última TAMAR / BADLAR publicada y su promedio (informativo), los días hábiles del promedio (5), la **antigüedad tolerada** del último valor (5 días hábiles, parámetro) y el valor proyectado a mano por tasa y mes |
| Costos asociados (ABM) | Nombre, %, base (`CAPITAL` / `INTERESES`), momento (`INICIAL` / `POR_CUOTA`), IVA, activo. **Un costo inicial sobre intereses se rechaza** (en el servidor y con un CHECK) |
| Comisión de cancelación | Fija o escalonada por tramos (desde qué % del plazo transcurrido, % total y % parcial), más IVA. Sembrada: 3 % fijo. Sin exención automática por plazo |
| Simulador | Sistema, cuotas, frecuencia y TNA por defecto (francés, 24, mensual, 38 %) y el margen de seguridad (10 %) |
| Monedas | ARS y USD (fijas) |

Generales: una fila vigente por clave, con historial (baja + alta en una transacción). Tramos: se guardan como juego. Tasa manual: una vigente por tasa y mes.

---

## 2. El préstamo

`RO_T_CASHFLOW_PRESTAMO`. **Validado en el servidor** (`PrestamosParametros::validarPrestamo()`):

- Nombre, banco (una de las cuentas de `Prestamos::cuentas()`), moneda ARS/USD, capital > 0, desembolso.
- Tasa fija (TNA ≥ 0) o variable (TAMAR / BADLAR + spread, con criterio promedio o último valor).
- Sistema, cuotas (1 a 360), frecuencia de capital y de intereses (por defecto la de capital), primera cuota (opcional, posterior al desembolso), gracia (≥ 0 y < cuotas), forma de pago (informativa).
- **Francés sólo con frecuencias iguales**; **el plazo (cuotas × frecuencia de capital) múltiplo de la frecuencia de intereses**.

**El banco** sale del mismo desplegable que Saldos (`Saldos::getFilasDisponible()`): las cuentas de Interbanking (clave `IB|<clave>`) y las cuentas `BANCO` del catálogo que no son fondo (`CTA_<id>`). Se guardan la clave y el nombre. Informativo en esta etapa: el tablero no tiene filas por cuenta.

### Estados

| Estado | Guardado | Qué es |
| --- | --- | --- |
| Simulado | `ESTADO = 'SIMULADO'` | No suma en el tablero oficial. Se elimina con baja lógica (`VIGENTE = 0`) |
| Vigente | `ESTADO = 'VIGENTE'` | Suma en el tablero. No se elimina: se cancela. No vuelve a simulado |
| Cancelado | calculado | Tiene una cancelación total ya ocurrida (si la fecha es futura, sigue vigente hasta ese día) |
| Finalizado | calculado | Ya pasó la fecha de pago de su última cuota |

Cancelado y Finalizado **se calculan** (`PrestamosCalculo::estado()`): no se escribe nada al leer. Simulado → Vigente se hace con **Pasar a vigente** (confirmación), ajustando lo que haya cambiado.

---

## 3. El cronograma

`Class/PrestamosCalculo.php` es **la** regla, pura. Recibe el préstamo, sus costos, sus correcciones, sus cancelaciones, las tasas, los días hábiles y la curva ya leídos.

### 3.a Fechas

Cuotas = períodos de **capital**. El calendario va de a la frecuencia más corta: el primer vencimiento es la fecha de la primera cuota (por defecto, desembolso + un período de la frecuencia más corta) y los siguientes suman meses desde ahí, **al fin de mes si el día no existe**. Cada vencimiento lleva capital, intereses o los dos según el mes. Un bullet de 1 cuota anual con intereses mensuales es de 12 meses.

Una cuota en día no hábil se paga el **hábil siguiente** (`DiasHabiles::siguiente()`, con los ajustes de Calendario) y queda marcada como corrida; **los días de interés se cuentan entre fechas contractuales**.

### 3.b Intereses

`saldo × TNA × días / 365`, días exactos. Con capital más frecuente que los intereses el saldo cambia adentro del período: se devenga por tramos y se paga junto en el vencimiento de intereses. Referencia de prueba: TNA 29,69 %, 30 días, $ 10.000.000 → $ 244.027,40.

### 3.c Amortización

- **Alemán**: capital fijo = capital / (cuotas − gracia).
- **Francés**: cuota con la tasa del período nominal (TNA × 30/90/180/365 / 365) sobre los períodos que quedan; interés por días exactos y capital = cuota − interés. **A tasa variable la cuota se recalcula al inicio de cada período** con la TNA de ese período, sobre el saldo y los períodos que quedan.
- **Bullet**: todo el capital en la última cuota.
- **Gracia parcial**: las primeras G cuotas pagan sólo intereses.
- **La última cuota cierra el capital exacto**, salvo que su capital esté corregido (y entonces, si no cierra y el préstamo está vigente, avisa).

### 3.d Costos

Iniciales: % del capital, el día del desembolso; en el tablero el desembolso va **bruto** a Desembolsos y los costos a Intereses y gastos. Por cuota: % del interés (base INTERESES) o del **saldo de capital al inicio del período** (base CAPITAL). Con IVA, la alícuota de Parámetros › Generales.

### 3.e Correcciones

`RO_T_CASHFLOW_PRESTAMO_CUOTA_AJUSTE`: una vigente por (préstamo, cuota, concepto), concepto `CAPITAL`, `INTERES`, `TNA` o `COSTO:<id>`. Pisa lo calculado, queda marcada con usuario y fecha, y se deshace con baja lógica. Una corrección de capital mueve el saldo de las cuotas siguientes. **Las correcciones de cuotas posteriores a una cancelación parcial no se aplican** (avisa y vuelven si se deshace la cancelación).

### 3.f Real y proyectado

Fecha de pago ≤ hoy → real. **Al tablero va sólo lo de hoy en adelante**: lo anterior ya está en el Saldo Inicial (como en Llaves). La pestaña muestra el cronograma completo.

### 3.g Moneda

USD → pesos con `DolarFuturo::resolver()` por fecha de pago, el desembolso incluido. Sin curva, el pago queda con importe null, no suma y avisa.

### 3.h TEA y CFT

TEA = (1 + TNA × d / 365)^(365 / d) − 1, con d los días nominales del período de intereses (referencia: 29,69 %, d = 30 → 34,09 %). CFT: la tasa efectiva anual que iguala el desembolso neto de costos iniciales con todos los pagos, por bisección. Los dos, informativos.

---

## 4. Tasa variable

`Class/PrestamosTasas.php`. Lee `[XL-APPS].sistemas.dbo.FP_BCRA_VARIABLES_MONETARIAS` y `FP_BCRA_VARIABLES_LOG` desde `central`, como `DolarFuturo::ORIGEN_DIVISAS`, **por `IdVariable`** (44 TAMAR, 7 BADLAR). Los valores vienen en %: se dividen por 100 una sola vez (`tnaDecimal()`). Nunca lanza.

**Precedencia** (`PrestamosTasas::base()` + la corrección en el cálculo): corrección de la cuota → valor manual del mes de inicio del período → si el período ya empezó, promedio de los N registros anteriores a su inicio (o el más viejo disponible, con aviso) → si es futuro, el promedio de los últimos N, constante (o el último publicado, si el préstamo lo pide). TNA = base + spread.

**Avisos**: última corrida del log en `ERROR` o `SIN_DATO` (atención); último dato con más días hábiles de los tolerados (atención; parámetro, 5 por defecto); XL-APPS ilegible (crítico: los variables no se proyectan, los fijos sí). Medido el 25/11/2026: el último dato era del 20/11 con el log en `OK` — el BCRA publica con atraso, de ahí la tolerancia de 5.

---

## 5. Cancelación anticipada

`RO_T_CASHFLOW_PRESTAMO_CANCELACION`: fecha, total o parcial, monto y modo. **Una sola total vigente** por préstamo (índice único filtrado). Se simula antes de registrar (`Prestamos::simularCancelacion()` + `compararCancelacion()`): total a pagar, intereses y gastos que se ahorran, costo de la comisión, resultado neto y los pagos del préstamo mes a mes con y sin cancelar. **Registrar vuelve a validar en el servidor**. Se deshace (baja lógica) mientras la fecha no haya pasado.

- **Total**: saldo + intereses devengados a la fecha + comisión + IVA + los costos por cuota de base intereses sobre lo devengado. Lo posterior no va.
- **Parcial**: adelantado + devengado + comisión + IVA; reducir cuota (alemán: saldo / cuotas que quedan; francés: cuota nueva) o plazo (misma cuota, termina antes). Un monto igual o mayor al saldo se rechaza: es una total.
- **Comisión**: fija o del tramo del % de plazo transcurrido (días desde el desembolso / días hasta el último vencimiento).
- En dólares, aviso fijo de la normativa cambiaria en el modal.

---

## 6. El tablero

### 6.a La sección Financiamiento

`sql/cashflow_prestamos_fila.sql` (72): sección `FINANCIAMIENTO`, ORDEN 85, sin padre, rol `MOVIMIENTO`, con tres grupos de tres filas y el subtotal:

| Fila | Tipo | Serie | Grupo / Naturaleza |
| --- | --- | --- | --- |
| Desembolsos de préstamos (/ proyectados / simulados) | INGRESO | `DESEMBOLSO_REAL` / `_PROY` / `_SIM` | `FIN_DESEMBOLSO` · REAL / PROYECTADO / SIMULADO |
| Amortización de capital (…) | EGRESO | `CAPITAL_REAL` / `_PROY` / `_SIM` | `FIN_CAPITAL` |
| Intereses y gastos (…) | EGRESO | `INTERESES_REAL` / `_PROY` / `_SIM` | `FIN_INTERESES` |
| Total Financiamiento | SUBTOTAL | — | — |

**Entra en el Flujo Neto (sin cobertura) por su posición**: está arriba de Resultados (100) y de Cobertura (110), sin tocar el motor. Lo fija una prueba que corre el motor con esta estructura.

**Los desembolsos cuentan en el KPI de Ingresos** (son filas INGRESO). El tooltip de la tarjeta dice "Incluye $ X de desembolsos de préstamos" cuando el período tiene alguno.

### 6.b El registro: se reusa `FINANCIERO`

La entrada `FINANCIERO` ya declaraba `'tab' => 'prestamos'`. Pasa a disponible, con nombre "Préstamos", las nueve partes y los tres totales (`DESEMBOLSO`, `CAPITAL`, `INTERESES`) en `componentes`. **`MOVIMIENTOS` queda declarada en desuso y rinde cero**: la fila vieja de Ajustes (ID 20, inactiva) la nombra, y **el validador de la estructura revisa también las filas inactivas**; sin la serie, Parámetros › Cashflow no dejaría guardar nada desde que se publica el código.

### 6.c El interruptor "Incluir simulaciones"

- En el tablero, al lado de las vistas, **apagado por defecto y no se recuerda**.
- Viaja en el pedido (`getTablero&simulaciones=1`); `Cashflow` acepta un cuarto parámetro `$opciones` y se lo pasa a cada proveedor con `CashflowProvider::conOpciones()` antes de `series()`. **El motor no lo mira.** Sólo `PrestamosProvider` lo lee (`opcion('simulaciones')`: `false`, `true` o una lista de ids — la usa el simulador).
- Apagado, las series `_SIM` rinden **cero** y el front esconde las filas con `NATURALEZA = 'SIMULADO'` (`filaDibujable()`). Prendido, se dibujan en itálica y atenuadas con la etiqueta *Simulado*, y arriba aparece "Incluye préstamos simulados: no es el cashflow oficial". KPIs, saldo final, columnas en rojo y cobertura automática se recalculan en el servidor.
- `NATURALEZA = 'SIMULADO'` se sumó en `CashflowEstructura::NATURALEZAS`, `grupos()`, el editor de Parámetros › Cashflow y `Js/Cashflow.js`. Un grupo sin parte simulada no se avisa.

### 6.d Pestaña = tablero

`PrestamosProvider::armarSeries()` acumula **la misma lista** que `Prestamos::armarProyeccion()` agrupa (`Prestamos::pagosTablero()`), con `Horizonte::acumular()` / `EjeVista`. Una prueba lo fija columna por columna con préstamos en ARS y USD, fijos y variables, con gracia, con cancelación y simulados.

---

## 7. La pestaña

`Class/Prestamos.php` (arma, sin reglas propias; nunca lanza al leer), `Controller/PrestamosController.php`, `Tabs/prestamos.php`, `Js/Prestamos.js`, `Css/Prestamos.css`. Un pedido (`getPestana`) para las cuatro sub-pestañas.

- **Préstamos**: la lista con banco, moneda, capital, tasa, sistema, cuotas, estado, saldo a hoy, próxima cuota, TEA y CFT; filtro por estado (con Finalizado); acciones alta, editar, cronograma, pasar a vigente, cancelar y eliminar simulado.
- **Cronograma**: por cuota, vencimiento, fecha de pago (corrida), días, tasa y su origen, saldo inicial, capital, interés, cada costo, comisión, total y real/proyectado; corrección por cuota con deshacer; el criterio de la tasa al pie; las cancelaciones con deshacer.
- **Proyección**: el eje del cashflow (tres vistas, columnas fijas, totales, exportar), préstamo → concepto, con su propio interruptor de simulaciones (dos totales: oficial y con simulados).
- **Simulador**: ver parte 8.
- **Tarjetas** (plegables): deuda vigente (saldo a hoy de los vigentes en pesos; USD con el dólar futuro de hoy), cuotas del horizonte, próxima cuota, necesidad (a pedido, "—" hasta calcularla) y simulados.

---

## 8. Simulador

`Class/PrestamosSimulador.php` (puro) y `Prestamos::necesidad()` / `simularPrestamo()` / `comparar()`.

- **Cuánto haría falta pedir**: corre el tablero oficial (sin simulaciones) **a pedido** y toma la última fila `SALDO_FINAL` (con cobertura). En el orden del tiempo (`Horizonte::secuencia()`): primer día en negativo y mínimo. Necesidad = |mínimo|. **Sugerido = necesidad × (1 + margen) / (1 − % de costos iniciales activos, con su IVA)**. Referencia: 10.000.000, margen 0, 1,2 % → 10.121.457. Si nunca queda en negativo, lo dice.
- **Simular préstamo**: arma un simulado con el sugerido, desembolso el primer día en negativo o el hábil anterior, y las condiciones por defecto. **Si el primer negativo cae en una columna mensual, el desembolso va al primer hábil de ese mes y la pantalla lo marca como aproximado.**
- **Con y sin**: el saldo con cobertura al cierre de cada mes, sin el simulado y con él (dos corridas del tablero).
- Las dos lecturas **liberan la sesión** apenas autorizan (`AuthCashflow::liberarSesion()`).

---

## 9. Avisos

Cada hecho, un aviso aparte: falta el script 70 (crítico); no se pudieron leer los préstamos, las tasas o la curva; un préstamo que no se pudo calcular (crítico); un variable sin tasa (crítico); log de la tasa en ERROR / SIN_DATO; tasa más vieja de lo tolerado; cuotas con el promedio más viejo disponible (info); mes sin dólar futuro para un préstamo en USD; vigente con capital corregido que no cierra; correcciones que no se aplican por una cancelación parcial; sin alícuota de IVA, sin calendario o sin cuentas de Saldos. **Los avisos de la pestaña llegan al tablero** (`Prestamos::avisosTablero()`); los de un simulado, sólo con el interruptor.

---

## Permisos

| Clave | Para qué |
| --- | --- |
| `cashflow.tab.prestamos` | Ver la pestaña (`getPestana`, `calcularNecesidad`, `compararSimulado`) |
| `cashflow.editar.prestamos` | Alta, edición, pasar a vigente, eliminar simulado, correcciones, simular y registrar cancelaciones, armar un simulado |
| `cashflow.editar.parametros.prestamos` | Parámetros › Préstamos |

Las tres en `sql/cashflow_prestamos_permisos.sql`, que **no asigna roles**. Toda escritura pasa por `AuthCashflow::usuarioDeEscritura()`; sin permiso de edición, los controles no se dibujan.

---

## Pruebas

```
php cashflow/tests/run.php prestamos
```

- `test_prestamos_tasas.php` — promedio de N, ya empezado contra futuro, manual, último valor, % → decimal, días hábiles, avisos del log, antigüedad, XL-APPS caído, y que no escribe en `[XL-APPS]`.
- `test_prestamos_calculo.php` — interés y TEA de referencia, alemán, francés y bullet con y sin gracia, sincronizadas y desacopladas, la última cuota cierra, costos iniciales y por cuota con IVA, corrimiento al hábil sin tocar los días, real/proyectado, correcciones (capital, interés, TNA, costo), cancelación total y parcial (reducir cuota y plazo, alemán y francés), comisión fija y por tramos, correcciones posteriores a una parcial, tasa variable (promedio, manual, francés recalculado, sin tasas), USD con y sin curva, estados, TEA y CFT.
- `test_prestamos_parametros.php` — validadores (francés desacoplado, plazo múltiplo, gracia, costo inicial sobre intereses, cancelaciones), copia al alta y que un cambio posterior no toque a los existentes, y el script 70.
- `test_prestamos_pestana.php` — lo anterior a hoy no va al tablero, lo de hoy es real, simulados con y sin interruptor, la lista y sus acciones por estado, la proyección (oficial y con simulados), las tarjetas, la cancelación simulada, las cuentas de Saldos y el cableado.
- `test_prestamos_tablero.php` — **pestaña = tablero, columna por columna**; partes = total; el interruptor llega al proveedor por el contrato y por el motor; la sección entra en el Flujo Neto (sin cobertura); los grupos de tres pasan el validador (con la fila vieja de Ajustes inactiva); el registro; el script 72; el front del tablero.
- `test_prestamos_simulador.php` — el monto sugerido de referencia, los costos iniciales con IVA, primer negativo y mínimo en el orden del tiempo, la fecha del desembolso (hábil anterior, columna mensual aproximada), `necesidad()` y `comparar()` con un tablero doble.
- Cableado en las existentes: `test_auth_cashflow.php`, `test_avisos.php`, `test_avisos_pestana.php`, `test_cargando.php`, `test_kpis_plegables.php`, `test_parametros_carga.php`, `test_providers.php`, `test_grupos.php`, `test_menu.php`, `test_tablas_controles.php`, `test_ayuda_articulos.php`.

---

## Archivos

| Archivo | Qué es |
| --- | --- |
| `Class/PrestamosCalculo.php` | Puro: el cronograma y la lista de pagos |
| `Class/PrestamosTasas.php` | TAMAR y BADLAR de XL-APPS (sólo lee) y la regla de la tasa base |
| `Class/PrestamosParametros.php` | Parámetros y préstamos: lectura, escritura y validadores |
| `Class/PrestamosSimulador.php` | Puro: necesidad, monto sugerido y fecha del desembolso |
| `Class/Prestamos.php` | Arma Parámetros, la pestaña, los pagos del tablero y el simulador |
| `Class/Providers/PrestamosProvider.php` | Las series de Financiamiento |
| `Controller/PrestamosController.php` | Los endpoints de la pestaña y de Parámetros |
| `Tabs/prestamos.php`, `Js/Prestamos.js`, `Css/Prestamos.css` | La pestaña |
| `Tabs/parametros_prestamos.php`, `Js/Parametros-Prestamos.js` | La sub-pestaña de Parámetros |
| `Ayuda/prestamos.md` | El artículo de ayuda (en revisión) |
| `sql/cashflow_prestamos.sql`, `sql/cashflow_prestamos_permisos.sql`, `sql/cashflow_prestamos_fila.sql` | Los scripts 70 a 72 |

Lo que se tocó afuera del módulo: `Class/Cashflow.php` y `Class/CashflowProvider.php` (las opciones del pedido), `Class/CashflowRegistry.php` (`FINANCIERO`), `Class/CashflowEstructura.php` (`SIMULADO`), `Controller/CashflowController.php`, `Js/Cashflow.js`, `Tabs/cashflow.php` y `Css/Cashflow.css` (el interruptor), `Js/Parametros-Estructura.js` (la etiqueta), `Class/AuthCashflow.php`, `Class/Menu.php`, `Class/Parametros.php` y `Tabs/parametros.php`.

---

## Pendientes de confirmar

- **La forma de pago** (débito automático o transferencia) sólo se guarda; no tiene efecto en esta etapa.
- **El banco** es informativo: el desembolso y las cuotas no se asignan a una cuenta en el tablero.
- **La lectura del débito real de la cuota desde Interbanking** queda fuera de alcance: lo de hoy se toma como pagado.
- **El francés con tasa fija no recalcula la cuota** después de una corrección de interés o de TNA: el capital absorbe la diferencia y la última cuota cierra el saldo.
