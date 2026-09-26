# Módulo Logística Local — los fleteros

Pestaña **Proveedores → Logística Local**, la fila *Logística* del tablero, y las dos sub-pestañas nuevas de Parámetros: **Generales** y **Logística**.

Rama: `feature/logistica-local-parametros-generales`

---

## La idea en una línea

**A cuatro fleteros se les paga por hora, con un régimen mensual constante; el valor hora se reajusta cada tres meses por inflación, y el pago sale en dos cuotas iguales, el 2do y el 4to viernes de cada mes.**

```
Parámetros › Logística              Parámetros › Generales
  RO_T_CASHFLOW_LOGISTICA_FLETEROS    RO_T_CASHFLOW_INFLACION_MES
  HORAS_MES                           % de cada mes calendario
  VALOR_HORA_BASE  (ya ajustado)              │
  MES_BASE         ('Y-m')                    │
        │                                     │
        │      ┌──────────────────────────────┘
        ▼      ▼
   ┌────────────────────────────────────────────────┐
   │  valor hora del mes M                          │
   │  = VALOR_HORA_BASE × Π (1 + ajuste_i / 100)    │
   │    por cada ajuste i entre MES_BASE y M        │
   │  ajuste_i = inflación(mes_i) + (mes_i − 1)     │
   │                               + (mes_i − 2)    │  ← SIN componer
   └────────────────────────────────────────────────┘
        │
        │  importe del mes = HORAS_MES × valor hora del mes
        ▼
   ┌────────────────────────────────────────────────┐
   │  mitad y mitad en los dos pagos del mes        │
   │  2do y 4to viernes, corridos al día hábil      │
   │  ANTERIOR si no son hábiles                    │  ← Parámetros › Generales
   │  (con override manual por mes y nº de pago)    │
   └────────────────────────────────────────────────┘
        │
        │  los pagos con fecha ≤ HOY no se proyectan
        ▼
   Horizonte::acumular()  ──►  serie LOGISTICA / PAGOS  ──►  fila del tablero
```

**Todo es proyección. No hay parte real.** Los pagos a los fleteros no se leen de ningún comprobante: se calculan. Por eso la fila del tablero no está partida en *Real* y *Proyectado* como las dos de Comercio Exterior, y por eso no hay nada que conciliar contra Tango.

**Los importes son finales: sin IVA ni ningún otro concepto.**

---

## Ejecución de los scripts

Todos contra `central`, en este orden:

| # | Script | Qué hace | Si no se corre |
| --- | --- | --- | --- |
| 1 | `sql/migracion_parametros_modulo.sql` | *(ya existía)* Agrega `MODULO` a `RO_T_CASHFLOW_PARAMETROS` | El script 2 **no migra nada** y lo dice. La sub-pestaña Generales rescata igual los parámetros y avisa |
| 2 | `sql/cashflow_parametros_generales.sql` | Mueve a `MODULO = 'GENERALES'` los parámetros que hoy figuran como de Ventas, y crea `RO_T_CASHFLOW_INFLACION_MES` y `RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT` | **Ningún cálculo cambia** y nada se rompe. Los cuatro parámetros se siguen viendo y editando en Generales, con un aviso que nombra el script. No se puede cargar inflación —los valores hora se proyectan sin ajuste trimestral— ni mover una fecha del cronograma, y las dos tarjetas lo dicen |
| 3 | `sql/cashflow_logistica_fleteros.sql` | Crea `RO_T_CASHFLOW_LOGISTICA_FLETEROS`. **No siembra ningún fletero** | La pestaña Logística Local **no falla**: muestra la planilla vacía y avisa. La fila *Logística* del tablero va en **cero**, que es lo que va hoy, y el proveedor lo dice con su propio aviso |

**Ninguno crea filas del tablero.** La fila `LOGISTICA` ya existe en `RO_T_CASHFLOW_CONF_FILA` —sección *Costos Directos*, apuntada a `LOGISTICA` / `PAGOS`— desde `sql/cashflow_estructura.sql`. Lo único que cambia es que ahora el proveedor existe, así que esa fila deja de rendir cero.

### Después de correrlos

- `MODULO = 'VENTAS'` con `GRUPO = 'GENERAL'` tiene que quedar **vacío**. El script lo verifica e imprime el resultado. Si queda alguno, existe y no se ve en ninguna pantalla: la sub-pestaña Ventas ya no dibuja generales.
- Dar de alta los cuatro fleteros desde *Parámetros › Logística*: **OGGIBA** (Barone Gianluca), **OGSEBA** (Barone Sergio Walter), **OGDARI** (Ríos Daniel Alejandro) y **OGTAPI** (Tapia Ernesto Javier). Verificado contra `CPA01`: los cuatro códigos existen.
- Cargar sus horas, su valor hora base y su mes base. **Sin los tres no se proyectan**, y la pestaña lo avisa por fletero.
- Cargar la inflación en *Parámetros › Generales*, o dejarla en modalidad **Constante** y apretar *Aplicar a todos*.
- **Excluir a los cuatro de Cuentas a Pagar Locales**, desde el maestro de Proveedores Locales. Ver *La exclusión no la hace este módulo*, abajo. Mientras no estén excluidos, el tablero **cuenta su pago dos veces** y el cuadro cierra igual.
- Dar el permiso `cashflow.tab.logistica_local` a los roles que corresponda, en el módulo de Gestión de Usuarios. Ver *Permisos*.

---

## 1. Parámetros › Generales: por qué existe

Cuatro parámetros vivían bajo la sub-pestaña **Ventas** y no son de Ventas:

| Parámetro | A qué afecta de verdad |
| --- | --- |
| `horizonte_dias` | **El eje de todo el módulo.** Lo lee `Horizonte::desdeParametros()`, y con él se dibujan el tablero y las diez pantallas con eje temporal |
| `horizonte_meses` | Ídem |
| `feriados_comercio` | Marca columnas en todas esas pantallas |
| `alicuota_iva` | Ventas, pero es una constante general del negocio |

Mientras vivieron ahí, tocarlos **parecía mover una sola pantalla**. Ahora viven en *Generales*, que es la primera sub-pestaña.

**Ningún cálculo se entera del cambio, y está verificado contra el código:** el único lugar que pide parámetros *por módulo* es `Parametros::getModulosConDatos()`, que arma la pantalla. Todas las fórmulas los leen **por clave** con `getParametrosMap()`, que no filtra por módulo. Antes y después del script, `Horizonte`, `Ventas` y el tablero leen exactamente los mismos valores.

### Antes de correr el script, los parámetros no desaparecen

La sub-pestaña Ventas dejó de dibujar su bloque de generales en el mismo commit. Si Generales sólo mostrara lo que ya está en `MODULO = 'GENERALES'`, entre el deploy del código y la corrida del script esos cuatro parámetros **no se verían en ninguna pantalla**.

Por eso Generales los **rescata** de `VENTAS/GENERAL` cuando su propio grupo viene vacío, y avisa qué script correr. Se editan igual, porque `saveParametro()` guarda **por clave** y no le importa el módulo. Lo único que falta es dónde se muestran.

---

## 2. La inflación mensual

`RO_T_CASHFLOW_INFLACION_MES`: una fila por mes calendario, con el **% esperado en puntos** (2 = 2 %, no 0,02).

> La alícuota de IVA hace lo contrario —se guarda como tasa, 0,21— y por eso va dicho: son dos criterios distintos en la misma pantalla, y el que no está escrito se adivina mal.

### La ventana editable arranca dos meses antes del mes en curso

Lo que hay que proyectar son **los once meses siguientes** al actual (hoy: oct-26 a ago-27). Pero la grilla muestra **catorce**: también el mes en curso y los dos anteriores.

No es de más. Un ajuste suma la inflación de **su mes y la de los dos anteriores**, así que un fletero cuyo `MES_BASE` es anterior a hoy puede necesitar meses ya vencidos para su primer ajuste. Si la ventana arrancara el mes que viene, ese dato **no habría dónde cargarlo**: el mes quedaría sin proyectar para siempre, avisando que falta un número que la pantalla no ofrece tipear.

Los tres primeros van marcados: se editan, no se proyectan.

### Los meses viejos no se borran nunca

La ventana se corre sola con el calendario; la tabla **no se depura**. Un mes sigue haciendo falta durante tres meses después de haber pasado, y si se depurara, un ajuste que ayer se calculaba hoy se caería sin que nada hubiera cambiado.

### Las dos modalidades, y cuál es la fuente de verdad

| Modalidad | Qué se edita |
| --- | --- |
| **Constante** | Un único %, que al apretar *Aplicar a todos* se **estampa en cada mes** de la ventana |
| **Variable** | Un % por mes |

**En las dos, lo que el cálculo lee es siempre la tabla por mes.** El parámetro `inflacion_pct_constante` es lo último que alguien tipeó, no lo que se aplica: si después se pasa a Variable y se corrige un mes, ese mes vale lo que dice la tabla y el parámetro queda como estaba.

**Se estampa en vez de resolver la constante al calcular**, y esa es la decisión que sostiene todo lo demás. Resolviéndola al vuelo, los meses que van quedando atrás perderían su valor el día que alguien cambia el porcentaje, y **un ajuste ya proyectado cambiaría retroactivamente**.

Cambiar la modalidad **no toca ningún mes**: sólo cambia cómo se edita. La pantalla lo dice al guardar.

---

## 3. El cronograma de pagos

**2do y 4to viernes de cada mes.** Si el viernes no es hábil (`RO_T_CALENDARIO.DIA_LABORAL`, conexión `power`), el pago se corre al día hábil **ANTERIOR**.

### El corrimiento va para el otro lado que en Ventas, y no es un detalle

| | Dirección | Por qué |
| --- | --- | --- |
| **Ventas** — acreditación de cobranza | Al **próximo** día hábil | El banco no acredita antes de poder hacerlo |
| **Acá** — pago a un fletero | Al día hábil **anterior** | El compromiso es con una persona que cobra esa semana |

Las dos reglas son correctas y van en direcciones opuestas, así que **no comparten código**. Lo que sí comparten es el origen del calendario: `Ventas::getDiasHabiles()` es la única lectura de `RO_T_CALENDARIO` del módulo, y `CronogramaDatos` la reutiliza. Dos lecturas serían dos definiciones de "día hábil" esperando a desincronizarse.

### Si falta la fecha en el calendario, el mismo fallback que Ventas

`RO_T_CALENDARIO` está poblada hasta el **31/12/2027**, que cubre el horizonte actual (sep-26 → ago-27). Una fecha que no esté no rompe: se asume hábil de lunes a viernes y se deja un aviso, **deduplicado por mes** y con la misma redacción que el de Ventas. Dos textos distintos para la misma causa se leen como dos problemas.

Si la conexión `power` no responde, el cronograma se resuelve entero con ese fallback y se avisa: sin eso, una caída de ese servidor dejaría la pestaña de Parámetros y la fila del tablero en blanco por no poder decidir si un viernes es feriado.

### Siempre son exactamente dos pagos por mes

También en un mes con cinco viernes. El 2do y el 4to están definidos en cualquier mes —el más corto posible, un febrero de 28 días que arranca lunes, tiene exactamente cuatro— así que no hay un caso en el que falte uno. **El quinto viernes, cuando existe, no es un pago**: el acuerdo es quincenal, no semanal.

> Es el error que la prueba del mes con cinco viernes existe para atrapar: *"el cuarto viernes"* y *"el último viernes"* coinciden en tres de cada cuatro meses.

### Las fechas no se guardan; los overrides sí

El 2do y el 4to viernes son una función del calendario, y el corrimiento también. **Materializarlos sería tener una copia que se desactualiza sola** el día que cambia un feriado. Lo único que guarda `RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT` es lo que una persona decidió distinto, que es lo único que no se puede recalcular.

- La clave es **(mes, nº de pago)** y no la fecha: si se guardara contra la fecha calculada, el día que se agrega un feriado el cálculo daría otro día y el override quedaría colgado de una fecha que ya no existe.
- **El override no se corre al día hábil.** Lo cargó alguien que sabe algo que el calendario no sabe.
- **El motivo es obligatorio**, y se exige en el endpoint y no sólo en la pantalla.
- Se guarda también la **fecha calculada** del momento: es contra qué se compara el override, y recalcularla después mostraría una corrección distinta si en el medio cambió un feriado.
- **Volver al valor calculado no borra**: marca `VIGENTE = 0`. Con un `DELETE`, *"esta fecha nunca se tocó"* y *"se tocó y se volvió atrás"* son indistinguibles después del hecho.
- Un override no puede alejarse más de **31 días** de su viernes teórico. No es una regla de negocio: es una red contra el año mal tipeado, que es una fecha perfectamente válida que corre el pago doce meses.

### Se calculan los doce meses, se editan sólo los del tramo diario

La pantalla de Generales muestra **dos tablas**:

- Arriba, las fechas que caen **dentro del tramo diario** (`horizonte_dias`). Son las únicas donde el día exacto mueve una columna del tablero, y las únicas editables.
- Abajo, el resto del horizonte: **se calculan y se ven, no se editan.** Fuera del tramo, correr un pago tres días no cambia ningún número, porque la columna del mes es la misma.

**Pero se calculan todos, y no es de más:** son las que deciden qué mitad de un importe mensual cae dentro del tramo y cuál va a la columna del mes. Ver la sección 6.

---

## 4. El valor hora, y su ajuste trimestral

La regla completa, en cinco líneas:

1. El valor base **ya está ajustado** y rige **su mes y los dos siguientes**. Con `MES_BASE = sep-26`, rige en sep, oct y nov.
2. Cada tres meses contados desde `MES_BASE` hay un ajuste: **dic-26, mar-27, jun-27…**
3. El % de un ajuste es la **suma SIN componer** de la inflación de su mes y de los dos anteriores: `dic-26 = oct + nov + dic`.
4. **La base se corre:** cada ajuste se aplica sobre el valor del trimestre anterior, no sobre el valor base original.
5. El valor ajustado rige **desde el mes del ajuste**, para **los dos pagos** de ese mes.

Con 2 % mensual constante: `dic-26 = sep-26 × 1,06` y `mar-27 = dic-26 × 1,06`.

### Sin componer es una decisión de negocio, no una simplificación

El ajuste se pacta como *"la suma de la inflación del trimestre"*. Componer daría **6,12 %** donde la negociación dice **6 %**, y el valor resultante no coincidiría con ningún papel.

### No se redondea en cada trimestre

La cadena se calcula con precisión completa; el redondeo es presentación. Redondeando en cada paso, el valor de dentro de un año dependería de **cuántas veces se redondeó en el medio**, que es una propiedad del recorrido y no del acuerdo.

### El histórico real que la prueba reproduce

Tres cadenas de ajustes trimestrales, cada uno sobre el anterior:

```
              mar-26        jun-26 (+10 %)    sep-26 (+4 %)
fletero 1   13.856,12   →   15.241,74      →   15.851,41
fletero 2   12.338,95   →   13.572,85      →   14.115,76
fletero 3   18.032,70   →   19.835,97      →   20.629,41
```

`tests/test_logistica_valor_hora.php` los reproduce **al centavo**. La comparación es a un centavo y no a una milésima a propósito: son valores que se pactaron y se escribieron redondeados a dos decimales en cada trimestre, así que pedir 0,001 sería exigirle a la cuenta que reproduzca el redondeo de un papel.

### No hay override del valor ajustado

El valor de un trimestre **siempre** sale de la fórmula. Lo editable es la base —`VALOR_HORA_BASE` y `MES_BASE`, que es lo que efectivamente se pactó— y la inflación. Un tercer lugar donde tocar el mismo número haría imposible contestar por qué un mes vale lo que vale: no se sabría si sale de la cuenta o de una corrección que alguien cargó y nadie recuerda.

### En la planilla, con su tooltip

La tabla *Valor hora mes a mes* muestra qué valor rige en cada mes, con el mes de ajuste marcado y un tooltip que dice de qué ajuste sale y **qué meses de inflación se sumaron**:

> *Rige desde el ajuste de 2026-12, de 6,00 % = 2026-10: 2,00 % + 2026-11: 2,00 % + 2026-12: 2,00 % (suma sin componer), aplicado sobre el valor del trimestre anterior.*

**Ese texto lo arma el backend** (`LogisticaValorHora::explicar()`), porque es la explicación de una cuenta que hace el backend. Con el texto en el front, cambiar la regla obligaría a cambiarla en dos lados y el segundo se olvida.

---

## 5. Nunca un cero donde falta un dato

Es la regla de todo el módulo, y acá tiene cinco casos. Todos dejan el importe en **`null`** con su motivo, y todos avisan:

| Motivo | Cuándo | Qué se ve |
| --- | --- | --- |
| `SIN_HORAS` | El fletero no tiene `HORAS_MES` | El **valor hora sí se calcula y se muestra** —es un dato correcto y útil— y lo que falta es cuántas horas multiplicarlo |
| `SIN_BASE` | No tiene `VALOR_HORA_BASE`, o no tiene `MES_BASE` | Ningún mes proyecta |
| `ANTES_DE_BASE` | El mes del horizonte es anterior a `MES_BASE` | No hay ningún valor pactado para ese mes. Estirar la base hacia atrás sería afirmar algo que nadie cargó |
| `SIN_INFLACION` | Falta la inflación de alguno de los tres meses que suma un ajuste | Ese ajuste y **todos los siguientes** quedan sin valor, porque se apoyan en él. El aviso dice qué mes cargar |
| — | No hay ningún fletero activo | La fila del tablero va en cero **y el proveedor lo dice** |

Un cero en cualquiera de los cinco se leería como *"esa hora no se paga"* o *"no hay que pagar nada"*, que es lo contrario de lo que pasa.

**Los avisos van uno por fletero, no uno por mes.** Doce avisos que dicen lo mismo esconden el resto.

### Un mes que ningún fletero pudo calcular queda en `null`, no en cero

Si aportara cero sería indistinguible de un mes que proyecta cero. Un total sobre filas incompletas sigue siendo útil —dice cuánto se proyecta hoy— pero la distinción no se pierde.

---

## 6. Cómo cae cada mitad en el eje del tablero

`importe del mes = HORAS_MES × valor hora del mes`, y **se paga mitad y mitad** en los dos pagos del mes.

Las dos mitades **no se redondean**: el redondeo es presentación, y redondear acá haría que un importe impar perdiera un centavo por mes, todos los meses.

### Un mes partido por el final del tramo diario lleva media columna

Con `hoy = 25/09/2026` y `horizonte_dias = 28`, el tramo va del **25/09 al 22/10**. Octubre queda partido:

| Pago | Fecha | Dónde cae |
| --- | --- | --- |
| 1 (2do viernes) | 09/10 | **Columna diaria** `9/10` |
| 2 (4to viernes) | 23/10 | **Columna mensual** `Oct-26` |

Así que la columna mensual de *Oct-26* lleva **medio importe de octubre** y no el total. **No es un error**, y la pestaña lo dice al pie.

**Eso no se programa en este módulo.** Lo resuelve `Horizonte::acumular()`, que aplica la regla *"un importe va a un día O a su mes, nunca a los dos"*. La planilla entrega una lista de pagos con fecha e importe y deja que el eje los ubique. Escribir la decisión acá sería una segunda versión de la regla del eje, y la que ya existe está probada.

**Lo único que hace falta para que funcione** es que el cronograma esté calculado para **todos** los meses del eje y no sólo para los del tramo: si el pago del 23/10 no existiera, ese medio importe se perdería.

### Las tres vistas siguen siendo sumables

`total_horizonte = total_tramo + total_meses`, sin repetir nada. La prueba lo verifica sobre el reparto completo.

---

## 7. Un pago con fecha anterior o igual a hoy no se proyecta

Esa plata **ya salió** y ya está reflejada en el saldo bancario que abre el cuadro. Proyectarla sería pedir dos veces la misma plata.

### Cómo convive con el tramo diario, que arranca hoy

El tramo diario de `Horizonte` arranca **hoy**, así que la columna de hoy existe. Lo que no existe es el pago de hoy: si el cronograma lo pone hoy, ya se hizo.

**Consecuencia, y hay que tenerla presente: la columna de hoy nunca recibe nada de la fila Logística.** No es un hueco ni un error: es el criterio. Hoy (25/09/2026) el 4to viernes de septiembre cae justo hoy, así que es exactamente el caso que se ve en pantalla.

Es el mismo criterio que usa `CobElectronicosProvider` con su corte de pendientes, y va en la misma dirección: lo de hoy ya pasó por la cuenta.

### No se avisa, pero se ve

Lo excluido por fecha **no** se suma a `fuera_horizonte` ni genera un aviso: no es plata que el tablero informe de menos, es plata que ya salió. Avisarlo todos los días sería ruido sobre algo que no hay que hacer. Igual se ve en el detalle del fletero, marcado, con el motivo distinguiendo *"ya pasó"* de *"es hoy"*.

Lo **posterior** al horizonte sí va a `fuera_horizonte` y sí se avisa: esa plata todavía no salió y ninguna otra fila la muestra.

---

## 8. La exclusión de Cuentas a Pagar Locales no la hace este módulo

Los fleteros **tienen deuda real** en Cuentas a Pagar Locales. Mientras estén en las dos pestañas, el tablero cuenta su pago **dos veces**: una proyectada acá y otra por su deuda real. **El cuadro cierra igual**, así que nada lo delata.

La exclusión ya existe y es manual: `RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO`, que se carga desde el maestro de Proveedores Locales (ver `README-proveedores-locales.md`).

**Este módulo sólo AVISA.** Excluir a un proveedor saca su deuda real y ya facturada del tablero, y esa es una decisión de quien mira las cuentas a pagar, no un efecto de dar de alta un fletero. Es el mismo criterio que `ProveedoresTango::faltantes()`, que avisa y no da de baja.

El aviso nombra los códigos que faltan excluir, y sale en la pestaña **y en el tablero**, porque es lo único que explica un egreso duplicado.

---

## 9. El maestro de fleteros

`RO_T_CASHFLOW_LOGISTICA_FLETEROS`, con `COD_PROVEE` como clave.

### Sin precarga, y es a propósito

Los cuatro códigos se conocen y existen en `CPA01`. Sembrarlos desde el script igual sería un error: **las horas y el valor hora no los sabe el script**. Un fletero sembrado sin esos dos números no proyecta nada —queda en `null` y avisando— así que la única diferencia sería que la pantalla nace con cuatro filas vacías, con el riesgo de que alguien las lea como *"ya está configurado"*.

### El código se valida contra CPA01 y el nombre sale de ahí

Mismo patrón que el alta manual del maestro de Proveedores Locales, y la **misma clase**: `ProveedoresTango`. El autocomplete busca por código **o por nombre** —quien carga un proveedor se acuerda del nombre, no del código— y el alta **vuelve a chequear** con `existe()`, porque lo que manda el navegador es un pedido y no una autorización.

`NOM_PROVEE` **no se tipea**: si se pudiera, dos pantallas mostrarían dos nombres para el mismo código y ninguno sería *"el nombre del proveedor"*.

**El nombre se guarda igual, como copia.** La fila tiene que seguir siendo legible si Tango no responde —un código suelto no le dice nada a nadie— pero la pantalla muestra el de `CPA01` cuando puede leerlo, así que un cambio de nombre en Tango se ve enseguida. Un fletero cuyo código ya no está en `CPA01` se **marca** y no se da de baja solo: puede ser un código que Tango depuró, y decidir eso es de una persona.

### La collation no es un detalle

`COD_PROVEE` declara `Latin1_General_BIN`, la misma que `CPA01.COD_PROVEE`. Sin declararla, la columna toma la de la base —acento insensible— y `OGNUNE` y `OGNUÑE` serían el mismo valor acá y dos proveedores distintos en Tango. Hay 27 códigos con caracteres no ASCII, así que no es hipotético.

### Sin bajas físicas

`ACTIVO = 0` en vez de `DELETE`. Un fletero que dejó de trabajar conserva las horas y el valor hora con los que se proyectó, y se puede reactivar. Parámetros muestra los inactivos; la planilla y el tablero sólo miran los activos.

### Cero no es un valor válido

Ni en horas ni en valor hora, y lo garantizan un `CHECK` en la base y la validación en PHP. **Cero significa que no se le paga, y eso se expresa dando de baja al fletero**: cargado como cero, la planilla no podría distinguirlo de un olvido. Por el mismo motivo los tres campos nacen en `NULL` y **sin `DEFAULT`**: un `DEFAULT 0` convertiría la falta de dato en un dato.

### El mismo maestro se edita desde dos pantallas

El alta en *Parámetros › Logística*; las horas, el valor hora y el mes base también desde la planilla, que es donde se ve el efecto. Por eso los endpoints viven en `LogisticaController` y no en `ParametrosController`, y el módulo declara su propio `endpoint`, igual que hace `CASHFLOW`. **Dos endpoints escribiendo la misma tabla se desincronizan en la primera validación que alguien agregue de un solo lado.**

Los dos guardados mandan **los tres campos** y no sólo el que cambió: el guardado escribe la fila entera, así que mandar uno solo borraría los otros dos.

---

## 10. Las dos cotizaciones de Generales — solo lectura

| | De dónde sale | Qué se muestra |
| --- | --- | --- |
| **Dólar futuro** | `DolarFuturo::curva()` → `[XL-APPS].sistemas.dbo.FP_DOLAR_FUTURO_ROFEX` | La curva mes a mes, **cuándo se actualizó** y el aviso si no está disponible |
| **Dólar oficial BCRA** | `Cotizacion::ultimaHasta(hoy, COMPRADOR)` | El valor, **el día del que sale** y **la punta** |

**No se editan, y eso es la mitad de lo que las tarjetas comunican.** Las dos llegan por API y las mantiene otro proceso. Un campo editable al lado haría creer que se pueden corregir desde acá, y el único override que existe en todo el módulo es por contenedor, en Comercio Exterior.

### Por qué `ultimaHasta()` y no `delMes()`

Se pide **la última cotización conocida a hoy**, no el cierre del mes en curso: **el cierre de este mes no existe todavía**. Además `ultimaHasta()` devuelve el día del que sale, y `delMes()` no: un valor sin fecha no se puede contrastar contra nada.

### La punta es compradora

Es la que usa **todo el cashflow** —Ventas, Saldos, Exportaciones Tasky, Comex— y el default de la clase. La única pantalla que valúa a vendedor es *Dólares Cuenta Comitente*, y es deliberado. **La punta viaja con el valor y se muestra**: un importe valuado sin decir con qué punta se compara contra el BCRA comprador y parece estar mal.

---

## 11. Qué se ve en la pestaña

- **Tres tarjetas**: lo proyectado, cuántos fleteros activos hay y **cuántos no proyectan ningún mes**. La tercera cuenta sólo a los que no proyectan *nada*: un fletero al que le falta la inflación de marzo proyecta igual hasta febrero, y contarlo exageraría el problema.
- **La planilla**, con una fila por fletero: horas, valor hora base y mes base **editables**, y el eje temporal a la derecha. Las **tres vistas** (Días / Meses / Período completo) las dibuja `Js/eje-vistas.js`, con el payload que arma `EjeVista::armarAgrupado()` — el mismo que usan los Resumen de Cobranzas FR y May, porque es el mismo problema: una fila con importes en varias columnas a la vez.
- **El valor hora mes a mes**, con el mes de ajuste marcado y el tooltip que explica la cuenta.
- **El detalle de un fletero**: sus 24 pagos, con fecha, importe y estado (*columna del día* / *columna del mes* / *no se proyecta*, y si la fecha se corrió o se cargó a mano).
- **Exportar a Excel** en los tres cuadros, con el mecanismo de siempre (`data-exportar`): baja exactamente lo que se está viendo.

### La tabla se dibuja sobre la planilla, no sobre el eje

El payload del eje trae **sólo lo que se proyecta**: un fletero sin horas no tiene ninguna fila ahí. La planilla completa viaja aparte y es la que dice quiénes son todos y qué le falta a cada uno. **Dibujar sólo el eje escondería justo a los que hay que arreglar.**

---

## 12. Cuánto pone en el tablero

`LogisticaProvider` → `LOGISTICA` / `PAGOS`, en la sección **Costos Directos**.

- **La fila ya existía** en `RO_T_CASHFLOW_CONF_FILA` apuntada a ese par. Lo que cambió es que el proveedor existe, así que dejó de rendir cero. **Ningún script toca la estructura.**
- **No declara `componentes`.** Sus pagos son un universo propio: no son parte de `PROV_LOCALES` ni de ninguna otra serie. Declararlos como componentes haría que el validador aceptara las dos filas activas a la vez, y el mismo egreso entraría dos veces.
- **En pesos, sin tipo de cambio.** No hay nada que convertir ni que auditar.
- **Nunca tumba el tablero:** `calcular()` puede lanzar y `series()` lo envuelve. Además se atrapan los casos propios para rendir cero **con un aviso que diga qué pasó**, en vez del mensaje genérico de la clase base.

### Una fila en cero siempre se explica

Es el modo de falla propio de este proveedor, y tiene tres formas: falta el script, no hay fleteros, o los que hay no tienen los datos. En los tres, el aviso lo dice **y aclara que un cero no significa que no haya que pagar nada**. Un egreso en cero se lee como lo contrario de lo que pasa.

---

## 13. Permisos

La pestaña pasa por `AuthCashflow::puede('logistica_local')`, que busca la clave **`cashflow.tab.logistica_local`** en `FP_PERMISOS` (módulo 7), asociada al rol del usuario. `TabController` la vuelve a chequear antes de servir el archivo, así que el endpoint no es alcanzable sin el permiso.

**Ese permiso vive en el módulo de Gestión de Usuarios, que es otro repo.** Este repo no tiene ningún script que siembre permisos —no lo tiene para ninguna pestaña— así que darlo es un paso manual del deploy. Mientras no exista, el ítem **no aparece en el menú** y la pestaña responde 403: falla cerrada.

**Las sub-pestañas de Parámetros no tienen permiso propio.** El permiso es por pestaña, y *Parámetros* es una sola: quien la ve, ve sus nueve sub-pestañas. No se agregó granularidad nueva, y vale la pena tenerlo presente: **dar acceso a Parámetros ahora incluye el maestro de fleteros y la inflación**, que mueven importes del tablero.

### Una guarda que se arregló de paso

`AuthCashflow` moría con un *fatal* si `htdocs/Gestionusuarios` no estaba al lado —un checkout de finanzas solo, que es lo que pasa al correr las pruebas en una máquina limpia—. Ahora el `require` va condicionado y **falla cerrada**: sin padrón no hay usuario, así que `puede()` deniega todo y `TabController` responde 403. Nadie entra de más; lo que se pierde es la aplicación, no el control de acceso.

---

## Lo que este módulo no hace

- **No excluye a nadie de Proveedores Locales.** Sólo avisa. Ver la sección 8.
- **No calcula IVA ni ningún otro concepto.** Los importes son finales.
- **No tiene parte real.** No lee ni concilia comprobantes.
- **No permite editar el valor hora ajustado.** Siempre sale de la fórmula.
- **No crea ni mueve filas del tablero.** La fila `LOGISTICA` ya existía.
- **No precarga fleteros.**

---

## Pruebas

```
php tests/run.php logistica     los tres archivos del módulo
php tests/run.php cronograma    el cronograma de viernes
```

| Archivo | Qué fija |
| --- | --- |
| `tests/test_cronograma_pagos.php` | Qué viernes son el 2do y el 4to, el **mes con cinco viernes**, el corrimiento **hacia atrás**, el feriado encadenado, el **cruce de mes y de año**, el fallback de calendario con su aviso, el override (y que **no** se corre al día hábil), y el cronograma completo del horizonte con **los tres feriados reales** que caen en un 2do o 4to viernes: 25/12/2026, 26/03/2027 y 09/07/2027 |
| `tests/test_logistica_valor_hora.php` | Que un ajuste suma **su mes y los dos anteriores** (probado con inflación **variable**, porque con constante las tres cuentas posibles dan lo mismo), la aritmética de meses cruzando año, la inflación constante y variable, que **la base se corre**, el **histórico real de los tres fleteros al centavo**, los tres motivos de `null` y la ventana de meses editables |
| `tests/test_logistica_planilla.php` | El reparto **mitad y mitad** (también con centavos impares), la **exclusión de los pagos ≤ hoy** (incluido el de hoy), que **la columna de hoy queda vacía**, el **mes partido por el final del tramo** —media columna mensual—, que las tres vistas siguen siendo **sumables**, los cinco casos de `null` y los totales |
| `tests/test_logistica_provider.php` | El registro, que **no declara componentes**, la serie contra el eje **después de `normalizar()`**, los tres casos de fila en cero con su aviso, que un módulo que lanza **no tumba el tablero**, y el menú |
| `tests/test_menu.php` | Que `logistica_local` dejó de ser un placeholder y que la categoría Proveedores pasa a 2 de 2. Y, nuevo, que **sin sesión el menú filtrado viene vacío**: falla cerrada |
| `tests/test_providers.php` | 18 módulos disponibles (era 17) |

### Dos cosas que las pruebas destaparon

1. **`AuthCashflow` abortaba la suite entera** con un fatal antes de llegar a la mitad de los archivos, porque `htdocs/Gestionusuarios` no está en un checkout de este repo solo.
2. Con el fatal fuera del camino aparecieron **32 fallas de `test_menu`**: medía la estructura del menú contra `Menu::estructura()`, que **filtra por permisos**, y por línea de comandos no hay sesión. La estructura del menú es un hecho del código, así que ahora se pide con **`Menu::estructuraCompleta()`**, y el filtrado por permisos tiene su propia sección.

---

## Archivos

```
Class/
  Inflacion.php              el % de cada mes y la suma de a tres. Puro + lectura
  CronogramaPagos.php        2do y 4to viernes, corrimiento y override. PURO
  CronogramaDatos.php        el calendario bancario y los overrides. Lectura
  LogisticaValorHora.php     el ajuste trimestral. PURO
  LogisticaPlanilla.php      importes, mitades y motivos. PURO
  Logistica.php              el maestro de fleteros y la lectura de los insumos
  Providers/
    LogisticaProvider.php    LOGISTICA / PAGOS

Controller/
  LogisticaController.php    la planilla y el maestro (lo usan las dos pantallas)
  ParametrosController.php   + inflación y cronograma

Tabs/
  logistica_local.php        la pestaña (era un placeholder)
  parametros_generales.php   sub-pestaña Generales
  parametros_logistica.php   sub-pestaña Logística
  parametros.php             + los dos panes nuevos

Js/
  Logistica-Local.js
  Parametros-Generales.js
  Parametros-Logistica.js
  Parametros.js              − el bloque de generales, que se mudó

Css/
  Logistica-Local.css

sql/
  cashflow_parametros_generales.sql
  cashflow_logistica_fleteros.sql

tests/
  test_cronograma_pagos.php
  test_logistica_valor_hora.php
  test_logistica_planilla.php
  test_logistica_provider.php
```

---

## Pendientes conocidos

- **El permiso `cashflow.tab.logistica_local` hay que darlo a mano** en Gestión de Usuarios. Este repo no siembra permisos para ninguna pestaña.
- **La exclusión de los cuatro fleteros de Cuentas a Pagar Locales es manual** y hay que hacerla, o el tablero cuenta su pago dos veces. El módulo avisa mientras falte.
- **Las horas son constantes en todos los meses.** Si algún fletero pasa a tener un régimen distinto por mes, hoy no hay dónde cargarlo: habría que agregar una tabla por (fletero, mes), y la planilla ya está preparada para mostrar un importe distinto por mes.
- **El cronograma es uno solo** y hoy lo usa sólo Logística Local. Si otro egreso se paga con otro calendario, hará falta un cronograma por circuito, no un segundo juego de fechas en la misma tabla.
- **`RO_T_CALENDARIO` llega hasta el 31/12/2027.** Cubre el horizonte actual, pero en cuanto `horizonte_meses` lo pase, las fechas se resuelven por lunes-a-viernes y los feriados dejan de correr pagos. Se avisa, pero hay que extender la tabla.
