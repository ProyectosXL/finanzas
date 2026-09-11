/* ============================================================================
   MODULO COBRANZAS FR - FECHA DE COBRO MANUAL POR FACTURA
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : puede correrse en cualquier momento
   ----------------------------------------------------------------------------
   QUE RESUELVE

   La fecha probable de cobro de una factura pendiente se calcula como
   FECHA_EMIS + PPP del cliente. El PPP es un promedio, asi que sirve para el
   grueso de la cartera y no sirve cuando alguien ya sabe la fecha de esa
   factura puntual porque la hablo con el franquiciado.

   Esta tabla guarda esa fecha pactada, comprobante por comprobante.

   JERARQUIA: LA FECHA MANUAL MANDA
   Si hay fila aca para el comprobante, esa es la fecha de cobro. Si no, vale
   FECHA_EMIS + PPP. Y la fecha manual RECALCULA los dias -DATEDIFF entre
   emision y cobro-, con lo que cambia el tramo de la escala de descuento y el
   importe neto. No es solo mover el importe de columna.

   LA FECHA MANUAL SOBREVIVE A LA FACTURA
   No se limpia cuando el comprobante sale del listado -se cancela, se paga o
   entra en una propuesta-. Queda guardada y vuelve a aplicar sola si el
   comprobante reaparece. Borrarla automaticamente perderia una decision que
   alguien tomo, y el sintoma seria una fecha que se "desconfigura sola".

   LA CLAVE ES (T_COMP, N_COMP) Y NO INCLUYE AL CLIENTE
   El par tipo + numero identifica al comprobante en Tango; el codigo de
   cliente se guarda al lado para poder buscar y auditar, pero no forma parte
   de la unicidad. Si estuviera en la clave, un mismo comprobante cargado con
   dos codigos de cliente distintos daria dos fechas y ninguna ganaria.

   ES REEJECUTABLE: la tabla se crea solo si no existe.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL (
        ID          INT IDENTITY(1,1) NOT NULL,
        COD_CLIENTE VARCHAR(10)  NOT NULL,
        T_COMP      VARCHAR(10)  NOT NULL,
        N_COMP      VARCHAR(20)  NOT NULL,
        FECHA_COBRO DATE         NOT NULL,
        USUARIO     VARCHAR(50)  NULL,
        FECHA_ALTA  DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_COB_FMANUAL_ALTA DEFAULT (GETDATE()),
        FECHA_MOD   DATETIME     NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT UQ_RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL UNIQUE (T_COMP, N_COMP)
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CF_COB_FMANUAL_CLIENTE
        ON dbo.RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL (COD_CLIENTE)
        INCLUDE (T_COMP, N_COMP, FECHA_COBRO);
END
GO

PRINT 'Tabla de fecha de cobro manual lista.';
GO
