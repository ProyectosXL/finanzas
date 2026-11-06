/* ============================================================================
   INFORME ECONOMICO POR CANAL - AUDITORIA DEL MAESTRO DE RUBROS
   ----------------------------------------------------------------------------
   Base   : central. SOLO ARGENTINA: NO se corre en la base de Uruguay
            (TASKY_SA). A diferencia de los scripts 1 a 3, este habilita
            ESCRIBIR en el maestro de rubros, y en Uruguay el informe todavia
            no esta disponible. Sin esta tabla, cualquier intento de alta,
            cambio de nombre, baja o reactivacion se rechaza: es la segunda
            barrera, ademas de que la pantalla no consulta nada en 'uy'.
   Orden  : 5 de 5. No depende de ningun otro.
   ----------------------------------------------------------------------------
   QUE HACE

   Crea RO_T_IE_RUBRO_AUDITORIA: cada alta, cambio de nombre, baja y
   reactivacion de un rubro hecha desde Parametros -> Rubros.

     TIPO           VALOR_ANTERIOR       VALOR_NUEVO                     MOTIVO
     ALTA           NULL                 {"cod","nombre","cat"} (JSON)    opcional
     NOMBRE         el nombre anterior   el nombre nuevo                  opcional
     BAJA           '1'                  '0'                              obligatorio
     REACTIVACION   '0'                  '1'                              obligatorio

   El cambio de CATEGORIA sigue en RO_T_IE_RUBRO_CAT_AUDITORIA (script 2): no
   se migra ni se duplica. La pantalla junta las dos para "Ultimo cambio".

   El maestro (RO_T_RUBROS_CONTABLES) es de Control de Gastos: no se le agrega
   ninguna columna. La auditoria vive aparte.

   ----------------------------------------------------------------------------
   SOLO INSERCION

   Un trigger rechaza UPDATE y DELETE, igual que en RO_T_IE_RESUMEN_EDICION:
   una auditoria que se puede corregir no sirve como auditoria. El motivo es
   obligatorio en BAJA y REACTIVACION tambien en la base (CHECK), no solo en la
   pantalla: si alguien escribe a mano, la regla sigue valiendo.

   ----------------------------------------------------------------------------
   SI NO SE CORRE: la lista de rubros y el cambio de categoria siguen andando.
   Alta, nombre, baja y reactivacion quedan deshabilitados con un aviso que
   nombra este archivo, y el servidor los rechaza igual (un cambio sin
   auditoria no puede pasar).

   Reejecutable: todo va con IF NOT EXISTS. No borra nada.
   ========================================================================== */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_IE_RUBRO_AUDITORIA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_IE_RUBRO_AUDITORIA (
        ID             INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_RO_T_IE_RUBRO_AUDITORIA PRIMARY KEY,
        -- Sin FK al maestro a proposito: RO_T_RUBROS_CONTABLES no tiene UNIQUE
        -- sobre COD_RUBRO (su PK es un ID), y la auditoria tiene que
        -- sobrevivir a lo que Control de Gastos haga con el maestro.
        COD_RUBRO      VARCHAR(10)   NOT NULL,
        TIPO           VARCHAR(12)   NOT NULL CONSTRAINT CK_RO_T_IE_RUBRO_AUDITORIA_TIPO
                                     CHECK (TIPO IN ('ALTA', 'NOMBRE', 'BAJA', 'REACTIVACION')),
        VALOR_ANTERIOR NVARCHAR(400) NULL,
        VALOR_NUEVO    NVARCHAR(400) NULL,
        MOTIVO         NVARCHAR(500) NULL,
        USUARIO        VARCHAR(100)  NOT NULL,
        FECHA          DATETIME      NOT NULL CONSTRAINT DF_RO_T_IE_RUBRO_AUDITORIA_FECHA DEFAULT (GETDATE()),
        CONSTRAINT CK_RO_T_IE_RUBRO_AUDITORIA_MOTIVO
            CHECK (TIPO NOT IN ('BAJA', 'REACTIVACION') OR LEN(LTRIM(RTRIM(ISNULL(MOTIVO, '')))) > 0)
    );

    CREATE INDEX IX_RO_T_IE_RUBRO_AUDITORIA_RUBRO ON dbo.RO_T_IE_RUBRO_AUDITORIA (COD_RUBRO, ID);

    PRINT 'Creada dbo.RO_T_IE_RUBRO_AUDITORIA.';
END
ELSE
    PRINT 'dbo.RO_T_IE_RUBRO_AUDITORIA ya existe: no se toca.';
GO

IF OBJECT_ID('dbo.TR_RO_T_IE_RUBRO_AUDITORIA_SOLO_INSERCION', 'TR') IS NULL
    EXEC('CREATE TRIGGER dbo.TR_RO_T_IE_RUBRO_AUDITORIA_SOLO_INSERCION
          ON dbo.RO_T_IE_RUBRO_AUDITORIA
          INSTEAD OF UPDATE, DELETE
          AS
          BEGIN
              RAISERROR(''RO_T_IE_RUBRO_AUDITORIA es solo insercion: cada cambio se registra como un movimiento nuevo.'', 16, 1);
          END');
PRINT 'Trigger de solo insercion en su lugar.';
GO

PRINT 'Auditoria del maestro de rubros lista (solo Argentina).';
GO
