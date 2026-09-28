/* ============================================================================
   INFORME ECONOMICO POR CANAL - HISTORIAL DE EDICIONES
   ----------------------------------------------------------------------------
   Base   : central. Se corre TAMBIEN en la base de Uruguay (TASKY_SA), igual
            que sql/ie_estructura.sql.
   Orden  : 2 de 4. No depende de ningun otro.
   ----------------------------------------------------------------------------
   QUE HACE

   Crea dos tablas de auditoria:

     RO_T_IE_RESUMEN_EDICION       cada cambio de IMPORTE de un registro de
                                   RO_T_RESUMEN_FINAL_IE hecho desde el informe
     RO_T_IE_RUBRO_CAT_AUDITORIA   cada cambio de CAT_RUBRO_CONTABLE hecho desde
                                   Parametros -> Rubros

   El maestro de rubros y el resumen son de Control de Gastos: no se les agrega
   ninguna columna. La auditoria vive aparte.

   ----------------------------------------------------------------------------
   EL HISTORIAL ES SOLO INSERCION

   Deshacer y Restaurar original no borran movimientos: son un movimiento mas
   (TIPO = DESHACER / RESTAURAR) con su motivo. Un trigger rechaza UPDATE y
   DELETE sobre RO_T_IE_RESUMEN_EDICION: un historial que se puede corregir no
   sirve como historial.

   El PRIMER movimiento de cada ID guarda en IMPORTE_ANTERIOR el valor que dejo
   el proceso: es el "original" al que vuelve Restaurar.

   ----------------------------------------------------------------------------
   UN REPROCESO PISA LO EDITADO, Y ES LO ESPERADO

   RO_SP_REPROCESAR_RESUMEN_FINAL_IE borra el periodo y lo vuelve a insertar,
   asi que los IDs cambian. El historial NO se borra: queda como referencia
   (el modal lo muestra como "Ediciones perdidas por un reproceso", buscando
   por PERIODO + NRO_SUCURSAL + COD_RUBRO). Por eso se guardan esas tres
   columnas ademas del ID.

   SI NO SE CORRE: el informe se ve entero, pero no se puede editar ningun
   importe ni cambiar la categoria de un rubro; la pantalla lo avisa.
   ========================================================================== */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_IE_RESUMEN_EDICION', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_IE_RESUMEN_EDICION (
        ID               INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_RO_T_IE_RESUMEN_EDICION PRIMARY KEY,
        -- ID del registro de RO_T_RESUMEN_FINAL_IE. Sin FK a proposito: el
        -- reproceso borra el registro y el historial tiene que sobrevivirle.
        ID_REGISTRO      INT           NOT NULL,
        PERIODO          VARCHAR(7)    NOT NULL,
        NRO_SUCURSAL     INT           NULL,
        COD_RUBRO        VARCHAR(10)   NOT NULL,
        IMPORTE_ANTERIOR FLOAT         NOT NULL,
        IMPORTE_NUEVO    FLOAT         NOT NULL,
        MOTIVO           NVARCHAR(500) NOT NULL,
        TIPO             VARCHAR(10)   NOT NULL CONSTRAINT CK_RO_T_IE_RESUMEN_EDICION_TIPO
                                       CHECK (TIPO IN ('EDICION', 'DESHACER', 'RESTAURAR')),
        USUARIO          VARCHAR(100)  NOT NULL,
        FECHA            DATETIME      NOT NULL CONSTRAINT DF_RO_T_IE_RESUMEN_EDICION_FECHA DEFAULT (GETDATE()),
        CONSTRAINT CK_RO_T_IE_RESUMEN_EDICION_MOTIVO CHECK (LEN(LTRIM(RTRIM(MOTIVO))) > 0)
    );

    CREATE INDEX IX_RO_T_IE_RESUMEN_EDICION_REGISTRO ON dbo.RO_T_IE_RESUMEN_EDICION (ID_REGISTRO, ID);
    CREATE INDEX IX_RO_T_IE_RESUMEN_EDICION_CELDA ON dbo.RO_T_IE_RESUMEN_EDICION (PERIODO, NRO_SUCURSAL, COD_RUBRO);

    PRINT 'Creada dbo.RO_T_IE_RESUMEN_EDICION.';
END
ELSE
    PRINT 'dbo.RO_T_IE_RESUMEN_EDICION ya existe: no se toca.';
GO

IF OBJECT_ID('dbo.TR_RO_T_IE_RESUMEN_EDICION_SOLO_INSERCION', 'TR') IS NULL
    EXEC('CREATE TRIGGER dbo.TR_RO_T_IE_RESUMEN_EDICION_SOLO_INSERCION
          ON dbo.RO_T_IE_RESUMEN_EDICION
          INSTEAD OF UPDATE, DELETE
          AS
          BEGIN
              RAISERROR(''RO_T_IE_RESUMEN_EDICION es solo insercion: deshacer o restaurar se registran como un movimiento nuevo.'', 16, 1);
          END');
PRINT 'Trigger de solo insercion en su lugar.';
GO

IF OBJECT_ID('dbo.RO_T_IE_RUBRO_CAT_AUDITORIA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_IE_RUBRO_CAT_AUDITORIA (
        ID           INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_RO_T_IE_RUBRO_CAT_AUDITORIA PRIMARY KEY,
        COD_RUBRO    VARCHAR(10)   NOT NULL,
        CAT_ANTERIOR NVARCHAR(100) NULL,
        CAT_NUEVA    NVARCHAR(100) NOT NULL,
        USUARIO      VARCHAR(100)  NOT NULL,
        FECHA        DATETIME      NOT NULL CONSTRAINT DF_RO_T_IE_RUBRO_CAT_AUDITORIA_FECHA DEFAULT (GETDATE())
    );

    CREATE INDEX IX_RO_T_IE_RUBRO_CAT_AUDITORIA_RUBRO ON dbo.RO_T_IE_RUBRO_CAT_AUDITORIA (COD_RUBRO, ID);

    PRINT 'Creada dbo.RO_T_IE_RUBRO_CAT_AUDITORIA.';
END
ELSE
    PRINT 'dbo.RO_T_IE_RUBRO_CAT_AUDITORIA ya existe: no se toca.';
GO

PRINT 'Historial de ediciones del Informe Economico listo.';
GO
