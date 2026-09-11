/* ============================================================================
   MODULO CASHFLOW - INGRESOS: EXPORTACIONES TASKY
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_
   Orden   : se puede correr en cualquier momento, despues de
             sql/cashflow_estructura.sql. Sin el, la pestana proyecta con el
             plazo por defecto (30 dias) y la fila del tablero no existe.
   ----------------------------------------------------------------------------
   QUE ES ESTO

   Tasky es la razon social del grupo en Uruguay: mismo grupo, otra empresa.
   Se le factura en DOLARES y esas facturas pendientes (GVA12, cliente EXTASK,
   estado PEN) son cobranza a proyectar. La pestana Ingresos -> Exportaciones
   Tasky las lista y el proveedor ExportacionesProvider alimenta la fila
   EXPORTACIONES del tablero.

   NO HAY TABLA NUEVA: los datos salen de Tango. Lo que este script siembra es
   el parametro del plazo de cobro y la fila del tablero.

   ----------------------------------------------------------------------------
   1. EL PLAZO DE COBRO

   GVA12 trae la fecha de emision pero no una fecha de cobro, asi que se
   estima igual que en Cobranzas Mayoristas:

       Fecha de cobro estimada = FECHA_EMIS + exportaciones_tasky_dias_cobro

   Editable en Parametros -> Cobranzas, junto al plazo de mayoristas.
   
   ----------------------------------------------------------------------------
   2. LA VALUACION ES A DOLAR DE HOY, NO POR MES DE COBRO

   Todas las facturas se valuan al cierre del mes en curso de
   RO_V_DOLAR_OFICIAL_BCRA, que es la ultima cotizacion disponible. Es
   deliberado: la deuda esta fija en dolares y valuarla a hoy equivale a no
   suponer devaluacion, que es el criterio conservador que se pidio. Se aparta
   de la doctrina de Class/Cotizacion.php ("cada mes a su propio tipo de
   cambio"), que aplica a series de venta historica. Ver
   README-exportaciones-tasky.md.

   ES REEJECUTABLE: el parametro y la fila van con IF NOT EXISTS por clave.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. Parametro del plazo de cobro
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PARAMETROS', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS
                   WHERE CLAVE = 'exportaciones_tasky_dias_cobro')
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS
        (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, MODULO, GRUPO, FECHA_UPDATE, USUARIO)
    VALUES
        ('exportaciones_tasky_dias_cobro', '30', 'INT',
         'Dias de plazo a sumar a la fecha de emision para estimar el cobro de las facturas a Tasky (exportaciones)',
         'COBRANZAS', 'GENERAL', GETDATE(), 'SISTEMA');

    PRINT 'Parametro exportaciones_tasky_dias_cobro sembrado en 30 dias.';
END
GO

/* ----------------------------------------------------------------------------
   2. La fila del tablero

   Hasta ahora EXPORTACIONES era un proveedor declarado con
   'disponible' => false: en una base que corrio
   cashflow_estructura_disponibilidades.sql la fila ya existe (en
   DISPONIBILIDADES, orden 70) y rendia cero. Ahora tiene modulo y la serie
   COBRANZA es la misma, asi que esa fila no se toca: se respeta cualquier
   edicion posterior del usuario.

   En una base que NO corrio ese script la fila no existe y se crea en la
   seccion INGRESOS de la semilla, con orden 70. El subtotal de esa seccion
   estaba en 70: se corre a 80 para que la fila nueva quede antes, que es lo
   mismo que hace la semilla de cashflow_estructura.sql. Un UPDATE que no
   encuentra la fila no hace nada.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'EXPORTACIONES')
   AND EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = 'INGRESOS')
BEGIN
    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET ORDEN = 80, FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'SUB_INGRESOS' AND SECCION = 'INGRESOS' AND ORDEN = 70;

    INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
        (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES
        ('EXPORTACIONES', 'Exportaciones Tasky', 'INGRESOS', 'INGRESO', 1,
         'EXPORTACIONES', 'COBRANZA', 70, 1);

    PRINT 'Fila EXPORTACIONES creada en la seccion INGRESOS.';
END
GO

PRINT 'Exportaciones Tasky: parametro y fila del tablero listos.';
GO
