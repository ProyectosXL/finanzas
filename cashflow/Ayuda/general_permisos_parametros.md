---
titulo: Permisos y parámetros
pestana:
categoria: General
estado: en_revision
actualizado: 2026-11-23
usuarios: Tesorería, Administración, Dirección
bajada: Quién puede ver y quién puede editar cada pestaña, dónde queda registrado quién cambió qué, y qué se configura en Parámetros.
---

## Ver y editar: dos permisos por pestaña

Cada pestaña tiene dos permisos separados:

- **Ver**: abrir la pestaña y consultar sus datos, filtrar, ordenar, exportar y mirar los historiales.
- **Editar**: cargar, modificar, importar o dar de baja algo en esa pestaña.

Editar incluye ver: un permiso para editar sin el permiso para ver no habilita nada. Las pestañas que no escriben nada, como **Exportaciones Tasky** o **Gastos Locales**, tienen sólo el de ver.

En **Parámetros** el permiso para ver es uno solo, para todas las sub-pestañas, y el de editar va **por sub-pestaña**. Quien carga las cuentas de Saldos no necesariamente es quien toca el horizonte de todo el módulo.

El **Centro de ayuda** lo ve cualquiera que entre al cashflow, sin permiso propio.

## Quién da los permisos

Los permisos son del **rol** de cada usuario y se asignan en **Gestión de Usuarios**, no desde el cashflow. Si necesitás ver o editar una pestaña, pedíselo a quien administra los roles ahí.

Los administradores del sector Proyectos pueden ver y editar todo.

## Si no tenés permiso para ver

La pestaña no aparece en tu menú. En el tablero, la fila de esa pestaña se sigue viendo con su número, pero su nombre no te lleva a ningún lado, y en **Sobre estos números** su grupo no tiene **Ir a la pestaña**. Ver [Los avisos](ayuda:general_avisos#ir-a-la-pestana).

## Si no tenés permiso para editar

Ves lo mismo que quien edita: los datos, los filtros, **Exportar**, el orden por columnas y los historiales. Lo que no aparece son los botones y controles para cambiar algo:

- No se dibujan los botones de alta, guardar, importar ni conciliar, ni los formularios de alta.
- En las grillas de **Parámetros** cada campo se muestra como texto, con su valor. Los botones para mirar, como los de historial, siguen estando.
- En el tablero, las celdas de cobertura no se pueden tocar.

Esconder un botón es una comodidad, no la protección. Si igual llega un cambio sin el permiso para editar, **el servidor lo rechaza** y aparece un mensaje que empieza con *Tu rol no tiene permiso para modificar esta sección* y termina con *Podés consultarla, pero el cambio no se guardó.* El mensaje nombra el permiso que falta: es lo que hay que pedir en Gestión de Usuarios.

## Quién modificó y cuándo

Todo lo que se guarda en el cashflow registra quién lo hizo y cuándo. La fecha la pone el servidor, no tu computadora.

En la mayoría de las pantallas se ve al pasar el mouse por la celda que se cargó a mano —una fecha, un importe, un ajuste— o, en las grillas de **Parámetros** y los maestros, por un ícono chico en la fila:

| Texto | Qué quiere decir |
| --- | --- |
| **Cargado por** … | Quién lo dio de alta y cuándo. |
| **Modificado por** … | Quién lo cambió por última vez y cuándo. |
| **Dado de baja por** … | Quién lo dio de baja y cuándo. |
| **Proceso automático (…)** | No lo hizo una persona: lo grabó un proceso programado. |
| **sin usuario registrado** | Es de antes de que el cashflow registrara usuarios. |

La fecha va como día/mes/año y hora. El tablero no lo muestra: es para leer el flujo, no para auditarlo.

## Qué se configura en Parámetros

Los parámetros están agrupados por la pestaña que afectan. Ninguno está escrito en el código: cambiar un valor recalcula la proyección.

| Sub-pestaña | Para qué sirve |
| --- | --- |
| **Generales** | Lo que afecta a todo el módulo y no es una fecha: la alícuota de IVA, la inflación mensual esperada y las cuentas que no entran en Gastos Locales. También muestra, sin poder editarlas, la curva de dólar futuro y la cotización del BCRA. |
| **Calendario** | Todo lo que es una fecha: cuántas columnas diarias y mensuales tiene el horizonte, los feriados bancarios que se agregan o se quitan, los días sin venta y qué día se paga cada concepto del cronograma de pagos. |
| **Ventas** | El mix de cobro y plazos de cada canal, y la participación fija de respaldo para los meses sin historia. Alimentan la proyección de la pestaña Ventas. |
| **Saldos** | Bancos y cuentas, otros saldos, las cuentas de inversión y comitente, y la gestión de caja de cada local. Alimentan Saldos, las filas de saldo y caja de locales del tablero y la cobertura. |
| **Cob. Electrónicos** | Las procesadoras de pago y las alícuotas de retención con las que se calcula el neto de cada acreditación. |
| **Cheques** | Qué clientes operan con venta cobrada anticipada, y en cuántos días hábiles y con qué retención acredita un cheque depositado. |
| **Cobranzas** | Los plazos promedio de pago, calculados y editables, y las escalas de descuento por cliente. |
| **Compras Exterior** | Cómo se proyectan las compras del exterior que todavía no tienen contenedor, y qué parte de cada rubro del presupuesto se compra afuera. |
| **Logística** | Quiénes son fleteros, cuántas horas por mes trabajan y cuánto vale su hora. |
| **Prov. Locales** | Las retenciones de las facturas que se pagan por orden de pago, el excedente permitido al importar pagos y las listas para clasificar a cada proveedor. |
| **Tarjetas** | El maestro de tarjetas: tipo, banco, titular, porcentaje de cobertura y día de vencimiento del resumen. |
| **Alquileres** | Lo que la proyección de alquileres necesita y la app de alquileres no tiene: vencimiento, razones sociales, datos de cada local y pagos extraordinarios. |
| **Llaves y Renovaciones** | Lo que la proyección de llaves, renovaciones, obras y aperturas necesita y la app de alquileres no tiene. Ver **Llaves y Renov. Contratos**. |
| **Cashflow** | Qué secciones y filas tiene el tablero, en qué orden, y de qué módulo saca sus datos cada fila. |

### Parámetros › Cashflow

Desde acá se crean filas y secciones, se renombran, se mueven con las flechas y se habilitan o inhabilitan.

- Una fila nueva entra **inhabilitada**: no aparece en el tablero hasta que la habilitás.
- No hay bajas: lo que ya no se usa se inhabilita.
- Antes de guardar se revisa la estructura entera. Lo que dejaría un número mal —una fila activa sin origen de datos, dos filas leyendo lo mismo, que contarían dos veces el importe— no se guarda, y el mensaje dice qué pasaría.
- Mover una fila con las flechas puede separar un concepto con parte real y proyectada: se avisa antes de guardar.
- **No inhabilites las filas de stock de la cobertura**, **Inversiones disponibles** y **Dólares en cuenta comitente**. En el tablero no se ven, pero le dicen al motor cuánto hay en cada fondo: sin ellas, la cobertura rescata sin tope.

## Cómo carga Parámetros

Para que la pestaña abra rápido, **cada sub-pestaña carga cuando la abrís, y una sola vez**:

- Al entrar se carga sólo la primera, **Generales**, y la pantalla aparece apenas termina.
- Las demás cargan la primera vez que hacés clic en ellas, con su propio indicador de carga.
- Volver a una que ya abriste no vuelve a pedir nada.
- El **Actualizar** de una sub-pestaña recarga sólo esa.
- Si salís de Parámetros a otra pestaña del menú y volvés, se recarga todo.
- Si una sub-pestaña no pudo cargar, muestra el error, y al volver a abrirla lo intenta de nuevo.

Arriba de las sub-pestañas pueden aparecer avisos generales de configuración pendiente. Ver [Los avisos](ayuda:general_avisos).
