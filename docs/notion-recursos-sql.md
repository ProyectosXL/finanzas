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

## Resumen

| Objeto | Tipo | Servidor | Base |
| --- | --- | --- | --- |
| RO_T_CASHFLOW_VENTAS_HIST | Tabla | XL-TANGO | LAKER_SA |
| RO_T_CASHFLOW_VENTAS_INDICE | Tabla | XL-TANGO | LAKER_SA |
| RO_T_CASHFLOW_VENTAS_PARTIC | Tabla | XL-TANGO | LAKER_SA |
| RO_T_CASHFLOW_VENTAS_MIX | Tabla | XL-TANGO | LAKER_SA |
| RO_T_CASHFLOW_VENTAS_PRECHEQ | Tabla | XL-TANGO | LAKER_SA |
| RO_T_CASHFLOW_PARAMETROS | Tabla | XL-TANGO | LAKER_SA |
| SJ_CASHFLOW_VENTAS_HIST | Stored Procedure | XL-TANGO | LAKER_SA |
| RO_T_CALENDARIO | Tabla (lectura, externa) | XL-APPS | POWER_BI_CONTROL |
