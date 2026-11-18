/* ============================================================================
   CASHFLOW - VENTAS: LO QUE QUEDA FUERA DE LA VENTA
   ============================================================================
   Base    : central. Reejecutable: la tabla se crea solo si no existe, y no
             hay DROP ni carga de datos.
   Orden   : ANTES de volver a crear sql/SJ_CASHFLOW_VENTAS_HIST.sql, que es
             quien la llena. Ver README-ventas.md, "Orden de ejecucion de los
             scripts" y "Conciliacion contra el libro IVA ventas".
   ----------------------------------------------------------------------------
   POR QUE EXISTE
   --------------
   La venta del cashflow es la base de la PROYECCION, asi que suma solo lo que
   es venta del negocio: los clientes de los canales (FR y L = Franquicias,
   MA = Mayoristas, 000000 = Ecommerce) y los locales propios.

   El libro IVA ventas de contabilidad, en cambio, tiene TODO lo facturado:
   tambien regalias (OTBONG), canjes de puntos (OTICBC), gift cards (OTGIFT),
   exportaciones a Uruguay (EXTASK) y cualquier cliente nuevo que no sea de un
   canal. Cuando contabilidad compara y no le coincide, la diferencia esta aca:
   una fila por mes y cliente con lo que el SP dejo afuera y por que.

     Libro IVA ventas (neto + no gravado + exento)
       = venta del cashflow (RO_T_CASHFLOW_VENTAS_HIST, FACTURA)
       + NETO_SIN_IVA de esta tabla para el mismo mes

   Medido contra el libro de sep-26 y oct-26: cierra con diferencias de
   centavos (redondeo de renglon contra encabezado).

   QUE GUARDA
   ----------
   Importes del ENCABEZADO del comprobante (GVA12), que es lo que lee el libro
   IVA, con el signo de la nota de credito y EN PESOS: un comprobante en
   moneda extranjera (MON_CTE = 0, las exportaciones) trae gravado, exento e
   IVA en esa moneda y se convierten por su COTIZ.

   MOTIVO sale del prefijo del cliente (ver la PARTE E del SP):
     OT -> OTROS INGRESOS   regalias, canjes, gift cards: no es venta
     EX -> EXPORTACION      exportaciones a Uruguay
     resto -> SIN CANAL     un cliente que no es de ningun canal. Hay que
                            mirarlo: o es un ingreso que no es venta, o es un
                            canal nuevo que falta agregar al SP

   La tabla se reescribe en cada corrida del SP para el rango que corre (DELETE
   + INSERT, en la misma transaccion que la venta), igual que
   RO_T_CASHFLOW_VENTAS_HIST: por eso lleva solo USUARIO_ALTA / FECHA_ALTA.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_EXCLUIDAS', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_VENTAS_EXCLUIDAS (
        ANIO          SMALLINT       NOT NULL,
        MES           TINYINT        NOT NULL,
        COD_CLIENT    VARCHAR(20)    NOT NULL,
        RAZON_SOCIAL  VARCHAR(100)   NULL,
        MOTIVO        VARCHAR(30)    NOT NULL,
        COMPROBANTES  INT            NOT NULL CONSTRAINT DF_VENTAS_EXCL_COMPROBANTES DEFAULT (0),
        NETO_GRAVADO  DECIMAL(18,4)  NOT NULL CONSTRAINT DF_VENTAS_EXCL_GRAVADO DEFAULT (0),
        EXENTO        DECIMAL(18,4)  NOT NULL CONSTRAINT DF_VENTAS_EXCL_EXENTO DEFAULT (0),
        NETO_SIN_IVA  DECIMAL(18,4)  NOT NULL CONSTRAINT DF_VENTAS_EXCL_NETO DEFAULT (0),
        IVA           DECIMAL(18,4)  NOT NULL CONSTRAINT DF_VENTAS_EXCL_IVA DEFAULT (0),
        FECHA_CARGA   DATETIME       NOT NULL CONSTRAINT DF_VENTAS_EXCL_FCARGA DEFAULT (GETDATE()),
        USUARIO_ALTA  VARCHAR(50)    NULL,
        FECHA_ALTA    DATETIME       NOT NULL CONSTRAINT DF_VENTAS_EXCL_FALTA DEFAULT (GETDATE()),
        CONSTRAINT PK_RO_T_CASHFLOW_VENTAS_EXCLUIDAS
            PRIMARY KEY CLUSTERED (ANIO, MES, COD_CLIENT),
        CONSTRAINT CK_VENTAS_EXCL_MOTIVO
            CHECK (MOTIVO IN ('OTROS INGRESOS', 'EXPORTACION', 'SIN CANAL'))
    );

    PRINT 'RO_T_CASHFLOW_VENTAS_EXCLUIDAS creada.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_VENTAS_EXCLUIDAS ya existia: no se toca.';
GO
