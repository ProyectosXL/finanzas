/* ============================================================================
   MODULO CASHFLOW - TARJETAS PAGOS CORPORATIVOS: LAS POLIZAS DE SEGUROS
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_tarjetas.sql: la poliza tiene una FK contra
            el maestro de tarjetas. Sin el, este script corta con un mensaje.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

     RO_T_CASHFLOW_TARJETAS_POLIZA   las polizas de los proveedores de seguros

   Un proveedor de seguros es el que tiene, en el maestro de Proveedores Locales,
   RUBRO = SEGUROS y forma de pago TARJETA CORP. SUS FACTURAS DE TANGO NO SE USAN:
   salen de la grilla, del total y de la serie del tablero, y el seguro se
   proyecta SOLO CON SUS POLIZAS.

   Una poliza dice cuanto cuesta la vigencia, en que moneda, cuantos meses dura,
   cuantos de gracia, en que mes se paga la primera cuota y con que tarjeta. La
   regla de los pagos vive en Class/TarjetasSeguros.php, que es pura:

     cuota = total / (vigencia - gracia), un pago por mes desde el primero, en
     el debito de la tarjeta. Cuando la vigencia termina antes del fin del
     horizonte se repite el ciclo hasta que se cargue la siguiente poliza: en
     dolares por el mismo importe, en pesos ajustado por la inflacion sumada de
     la vigencia anterior.

   LA PERSONA DICE EL NUMERO O LA DESCRIPCION DE LA POLIZA, y con el proveedor
   identifica la poliza "siguiente": la del mismo proveedor y el mismo numero
   que empieza despues de que termina esta. El backend rechaza dos del mismo
   proveedor y numero con vigencias superpuestas.

   ----------------------------------------------------------------------------
   SIN BAJAS FISICAS Y CON AUDITORIA COMPLETA

   Dar de baja es VIGENTE = 0 con quien y cuando. Corregir es un UPDATE. Lo
   escribe siempre el backend con el usuario de la sesion.

   ES REEJECUTABLE: todo pregunta por OBJECT_ID o por sys.indexes y no se
   siembra ninguna fila. No borra nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NULL
BEGIN
    RAISERROR('Falta dbo.RO_T_CASHFLOW_TARJETAS. Corre primero sql/cashflow_tarjetas.sql: la poliza tiene una FK contra ella. No se creo nada.', 16, 1);
END
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_POLIZA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_TARJETAS_POLIZA (
        ID                INT IDENTITY(1,1) NOT NULL,

        /* El proveedor de seguros, con la collation y el largo de CPA01, y su
           razon social copiada. */
        COD_PROVEE        VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,
        RAZON_SOC         VARCHAR(100)  NULL,

        /* El numero o la descripcion: con el proveedor, es lo que identifica
           la poliza siguiente. */
        POLIZA            VARCHAR(100)  COLLATE Modern_Spanish_CI_AI NOT NULL,

        /* De la tarjeta salen el dia del debito y el % de cobertura. */
        ID_TARJETA        INT           NOT NULL,

        IMPORTE_TOTAL     DECIMAL(18,2) NOT NULL,
        MONEDA            CHAR(3)       NOT NULL,

        FECHA_INICIO      DATE          NOT NULL,
        VIGENCIA_MESES    SMALLINT      NOT NULL,
        GRACIA_MESES      SMALLINT      NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJPOL_GRACIA DEFAULT (0),

        /* El mes, no el dia: el dia lo pone la tarjeta. */
        MES_PRIMER_PAGO   CHAR(7)       NOT NULL,

        OBSERVACION       VARCHAR(500)  NULL,

        VIGENTE           BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJPOL_VIGENTE DEFAULT (1),

        USUARIO_ALTA      VARCHAR(50)   NULL,
        FECHA_ALTA        DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJPOL_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF     VARCHAR(50)   NULL,
        FECHA_MODIF       DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJPOL_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA      VARCHAR(50)   NULL,
        FECHA_BAJA        DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_TARJETAS_POLIZA PRIMARY KEY CLUSTERED (ID),

        CONSTRAINT FK_RO_T_CF_TARJPOL_TARJETA FOREIGN KEY (ID_TARJETA)
            REFERENCES dbo.RO_T_CASHFLOW_TARJETAS (ID),

        /* La tercera red: las mismas reglas las valida el backend con un mensaje
           que se entiende. */
        CONSTRAINT CK_RO_T_CF_TARJPOL_IMPORTE   CHECK (IMPORTE_TOTAL > 0),
        CONSTRAINT CK_RO_T_CF_TARJPOL_MONEDA    CHECK (MONEDA IN ('ARS', 'USD')),
        CONSTRAINT CK_RO_T_CF_TARJPOL_VIGENCIA  CHECK (VIGENCIA_MESES >= 1),
        CONSTRAINT CK_RO_T_CF_TARJPOL_GRACIA
            CHECK (GRACIA_MESES >= 0 AND GRACIA_MESES < VIGENCIA_MESES),
        CONSTRAINT CK_RO_T_CF_TARJPOL_MES
            CHECK (MES_PRIMER_PAGO LIKE '[12][0-9][0-9][0-9]-[01][0-9]'),
        CONSTRAINT CK_RO_T_CF_TARJPOL_POLIZA
            CHECK (LEN(LTRIM(RTRIM(POLIZA))) > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_TARJETAS_POLIZA.';
END
ELSE IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_POLIZA', 'U') IS NOT NULL
    PRINT 'dbo.RO_T_CASHFLOW_TARJETAS_POLIZA ya existe: no se toca.';
GO

/* La lectura de cada carga: las vigentes de un proveedor y su numero. */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_POLIZA', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE name = 'IX_RO_T_CF_TARJPOL_PROVEEDOR'
                     AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_POLIZA'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_TARJPOL_PROVEEDOR
        ON dbo.RO_T_CASHFLOW_TARJETAS_POLIZA (COD_PROVEE, POLIZA, VIGENTE)
        INCLUDE (FECHA_INICIO, VIGENCIA_MESES);
END
GO

/* CONTROL: los proveedores de seguros segun el maestro, y cuantas polizas
   vigentes tiene cada uno. No inserta ni modifica nada. */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_POLIZA', 'U') IS NOT NULL
BEGIN
    SELECT c.COD_PROVEE, c.NOMBRE, c.RUBRO, c.FORMA_PAGO,
           (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_TARJETAS_POLIZA p
            WHERE p.COD_PROVEE = c.COD_PROVEE COLLATE Latin1_General_BIN AND p.VIGENTE = 1)
               AS POLIZAS_VIGENTES
    FROM dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG c
    WHERE c.VIGENTE = 1 AND UPPER(LTRIM(RTRIM(c.RUBRO))) = 'SEGUROS'
    ORDER BY c.COD_PROVEE;
END
GO
