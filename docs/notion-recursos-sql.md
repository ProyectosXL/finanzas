# Recursos SQL — Módulo de Proyección de Ventas y Cobranzas

Documentación para Notion, con la estructura del inventario de recursos SQL.

Todos los objetos se crean con `sql/ventas_proyeccion.sql` y `sql/SJ_CASHFLOW_VENTAS_HIST.sql`.

---

- **Módulo**: Ingresos — Ventas

        **Tabla: RO_T_CASHFLOW_VENTAS_HIST**

**Servidor**: XL-TANGO

**Base**: LAKER_SA

**Descripción**: Histórico de ventas consolidado por año, mes, canal y tipo de comprobante, que el módulo de Flujo de Fondos usa como única base de cálculo para proyectar la venta futura. La puebla el stored procedure SJ_CASHFLOW_VENTAS_HIST leyendo Tango y los locales propios. Discrimina FACTURA de REMITO: la proyección usa exclusivamente las facturas, mientras que los remitos se guardan sólo como bloque de control para contrastar el total contra el tablero. Los cuatro canales del modelo son LOCALES, FRANQUICIAS, MAYORISTAS y ECOMMERCE. Los importes son netos sin IVA y las notas de crédito restan en el mes en que se emitieron.

---

- **Módulo**: Ingresos — Ventas

        **Tabla: RO_T_CASHFLOW_VENTAS_INDICE**

**Servidor**: XL-TANGO

**Base**: LAKER_SA

**Descripción**: Índice de variación que el usuario edita por mes desde la pestaña Ventas para proyectar la venta sobre el mismo mes del año anterior, según la fórmula VentaNetaProyectada = VentaNetaReal(año anterior) × (1 + índice). Es un único índice por mes del horizonte, no por canal, y se guarda contra el año y mes del mes proyectado. Un mes sin registro se proyecta con índice cero, es decir, igual al año anterior.

---

- **Módulo**: Ingresos — Ventas

        **Tabla: RO_T_CASHFLOW_VENTAS_PARTIC**

**Servidor**: XL-TANGO

**Base**: LAKER_SA

**Descripción**: Participación de cada canal sobre la venta con IVA, con el mismo criterio original/editado que usa RO_T_CASHFLOW_COMEX_CRONO_NAC. Guarda siempre el porcentaje calculado por el motor a partir del año anterior junto al override manual del usuario, de modo que se puede ver el valor propuesto y el corregido sin perder ninguno de los dos. El campo TIPO distingue la participación del tramo diario de 28 días de la de cada mes del horizonte. Un porcentaje editado nulo significa que rige el calculado. La suma de los cuatro canales debe dar exactamente 100%, y esa validación corre tanto en el front como en el servidor.

---

- **Módulo**: Ingresos — Ventas

        **Tabla: RO_T_CASHFLOW_VENTAS_MIX**

**Servidor**: XL-TANGO

**Base**: LAKER_SA

**Descripción**: Mix de medios de cobro y plazos de acreditación por canal, que convierte la venta diaria proyectada en cobranza. Cada fila define qué porcentaje de la venta de un canal se cobra por un medio de pago y a cuántos días se acredita; si la fecha resultante cae en un día no laboral, el motor la corre al próximo día hábil bancario. Los medios se dan de alta e inhabilitan desde la pestaña Parámetros: un medio inhabilitado deja de usarse en la proyección pero conserva su configuración para poder reactivarlo. Los medios activos de cada canal deben sumar 100% y ningún canal puede quedarse sin medios activos, porque su venta no se convertiría en cobranza.

---

- **Módulo**: Ingresos — Ventas

        **Tabla: RO_T_CASHFLOW_VENTAS_PRECHEQ**

**Servidor**: XL-TANGO

**Base**: LAKER_SA

**Descripción**: Neteo de cheques adelantados: registra los echeqs ya recibidos por ventas anteriores para descontarlos de la cobranza proyectada y no duplicar el ingreso. La tabla está creada y el circuito completo cableado (parámetro, método, controller y fila en la pantalla), pero permanece **vacía y devolviendo cero** porque la vista de origen todavía no existe. Cuando exista, sólo hay que enchufar el origen de datos: se toma la fecha del cheque, se le restan los días del parámetro dias_prechequeado para obtener la fecha teórica de la factura, y el importe se resta de la cobranza proyectada de esa fecha o de ese mes.

---

- **Módulo**: Parámetros (transversal)

        **Tabla: RO_T_CASHFLOW_PARAMETROS**

**Servidor**: XL-TANGO

**Base**: LAKER_SA

**Descripción**: Tabla clave/valor genérica donde viven todos los valores de negocio del Flujo de Fondos, para que ninguna constante quede escrita en el código. Hoy contiene la alícuota de IVA, el horizonte de proyección en días y meses, los feriados de comercio sin venta estimada y las participaciones fijas de respaldo. La columna MODULO indica a qué pestaña de la aplicación afecta cada parámetro, lo que permite agruparlos en sub-pestañas y que la tabla vaya absorbiendo los parámetros del resto de los módulos; GRUPO es la sección dentro de cada módulo. Se edita desde la pestaña Parámetros y cualquier cambio recalcula la proyección sin tocar código.

---

- **Módulo**: Ingresos — Ventas

        **Stored Procedure: SJ_CASHFLOW_VENTAS_HIST**

**Servidor**: XL-TANGO

**Base**: LAKER_SA

**Descripción**: Consolida el histórico de ventas y lo persiste en RO_T_CASHFLOW_VENTAS_HIST agregado por año, mes, canal y tipo de comprobante. Está basado en SJ_BI_SALES_LAKERS y conserva sus cuatro orígenes: facturas de franquicias y mayoristas (GVA12 + GVA53), remitos 599 (STA14 + STA20), ventas de locales propios (CTA02 + CTA03 en XL-LAKERBIS) y ecommerce. Recibe los parámetros opcionales @Desde y @Hasta; sin argumentos procesa los últimos 30 días. Internamente expande el rango a meses completos porque la tabla destino agrega al grano de mes: sin esa expansión, un rango que empezara a mitad de mes borraría el mes entero y sólo repondría la porción pedida. Persiste con DELETE del rango más INSERT, por lo que es reejecutable sin duplicar filas.

**Ejecución**: job diario de SQL Server Agent.

---

## Dependencia externa (no la crea este módulo)

- **Módulo**: Ingresos — Ventas

        **Tabla: RO_T_CALENDARIO** *(sólo lectura)*

**Servidor**: XL-APPS

**Base**: POWER_BI_CONTROL

**Descripción**: Calendario bancario que el motor de cobranzas consulta para saber si una fecha de acreditación es hábil. Es la única lectura del módulo fuera de LAKER_SA. Se usa el campo DIA_LABORAL, que excluye sábados, domingos y feriados nacionales; cuando una acreditación cae en un día no laboral, se corre al próximo hábil. **No se debe confundir con el calendario comercial de la venta**, que sí incluye sábados y domingos porque las sucursales abren, y que sólo excluye los tres feriados de comercio del parámetro feriados_comercio. La tabla está poblada hasta 2027: si el motor pide una fecha que no existe, asume hábil de lunes a viernes y deja un aviso en pantalla en lugar de fallar.

---

---

- **Módulo**: Cashflow (tablero)

        **Tabla: RO_T_CASHFLOW_CONF_SECCION**

**Servidor**: XL-TANGO

**Base**: LAKER_SA

**Descripción**: Secciones del tablero de consolidación: Disponible Inicial, Ingresos, Costo de Mercadería, Costos Directos, Costos Indirectos, Ajustes y Resultados. Ninguna está escrita en el código; la pantalla dibuja lo que haya en esta tabla. ROL define cómo participa la sección en el cálculo: SALDO abre el arrastre, MOVIMIENTO aporta flujo y DERIVADO sólo contiene filas calculadas. ID_PADRE permite colgar una sección de otra, para que un subtotal abarque varias secciones sin tocar el código. La clave primaria es CODIGO y no un IDENTITY, a diferencia de CONF_FILA: son pocas filas y así la referencia desde CONF_FILA.SECCION es una clave foránea de verdad. Se administra desde la sub-pestaña Cashflow de Parámetros.

---

- **Módulo**: Cashflow (tablero)

        **Tabla: RO_T_CASHFLOW_CONF_FILA**

**Servidor**: XL-TANGO

**Base**: LAKER_SA

**Descripción**: Filas del tablero de consolidación. Cada fila declara de qué módulo saca sus datos, mediante el par ORIGEN_PROVIDER + ORIGEN_SERIE, que apunta al registro de proveedores de cashflow/Class/CashflowRegistry.php. TIPO determina el signo y el significado: SALDO_INICIAL, INGRESO y EGRESO traen datos de un módulo, mientras que SUBTOTAL, FLUJO_NETO y SALDO_FINAL las calcula el motor. El alcance de las calculadas es posicional, resuelto con (SECCION.ORDEN, FILA.ORDEN), así que no se guarda ninguna referencia fila a fila y no puede haber referencias colgadas ni ciclos. No hay columna de signo: lo determina el TIPO, porque una columna aparte permitiría configurar un ingreso que resta. COMPUTA y ACTIVO son independientes: ACTIVO en cero saca la fila del tablero y COMPUTA en cero la muestra pero la deja fuera de toda suma, que es lo que resuelve la fila Ventas del Excel, que es venta y no caja. Las filas se dan de alta desde Parámetros y entran inhabilitadas; no se borran nunca.

---

- **Módulo**: Comercio Exterior

        **Parámetro: comex_tipo_cambio_usd** *(fila de RO_T_CASHFLOW_PARAMETROS)*

**Servidor**: XL-TANGO

**Base**: LAKER_SA

**Descripción**: Tipo de cambio con el que se valúan en pesos los pagos a proveedores del exterior. Vive bajo MODULO=COMEX y no bajo el del tablero porque la conversión la hace el proveedor de datos de Comercio Exterior y no el motor de consolidación: el motor nunca ve dólares, todos los proveedores le entregan pesos. Si el parámetro falta o está en cero, la fila se muestra en cero y el tablero deja un aviso que lo nombra, en lugar de fallar. El valor sembrado es de referencia y hay que actualizarlo; la pantalla muestra en el detalle de cada fila con qué tipo de cambio se valuó.

## Resumen

| Objeto | Tipo | Servidor | Base |
| --- | --- | --- | --- |
| RO_T_CASHFLOW_VENTAS_HIST | Tabla | XL-TANGO | LAKER_SA |
| RO_T_CASHFLOW_VENTAS_INDICE | Tabla | XL-TANGO | LAKER_SA |
| RO_T_CASHFLOW_VENTAS_PARTIC | Tabla | XL-TANGO | LAKER_SA |
| RO_T_CASHFLOW_VENTAS_MIX | Tabla | XL-TANGO | LAKER_SA |
| RO_T_CASHFLOW_VENTAS_PRECHEQ | Tabla | XL-TANGO | LAKER_SA |
| RO_T_CASHFLOW_PARAMETROS | Tabla | XL-TANGO | LAKER_SA |
| RO_T_CASHFLOW_CONF_SECCION | Tabla | XL-TANGO | LAKER_SA |
| RO_T_CASHFLOW_CONF_FILA | Tabla | XL-TANGO | LAKER_SA |
| SJ_CASHFLOW_VENTAS_HIST | Stored Procedure | XL-TANGO | LAKER_SA |
| RO_T_CALENDARIO | Tabla (lectura, externa) | XL-APPS | POWER_BI_CONTROL |
