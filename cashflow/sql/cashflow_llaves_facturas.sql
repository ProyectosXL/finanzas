/* ============================================================================
   MODULO CASHFLOW - LLAVES Y RENOVACIONES: FACTURAS MARCADAS COMO LLAVE
   (script 68)
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_alquileres_facturas.sql (53): las facturas
            de alquiler salen de Proveedores Locales solo con la condicion de
            seguridad de Alquileres, y es de ahi que se marcan como llave.
            Independiente de sql/cashflow_llaves.sql (66).
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Las facturas de las razones sociales de alquiler que son pagos de contrato
   (la "cuota inicial" de IRSA, la llave de Cencosud, las comisiones, la obra)
   se excluian en Alquileres > Facturas (script 61) y no sumaban en ningun
   lado. Ahora se marcan como llave, con el local, el concepto y el mes:

     RO_T_CASHFLOW_LLAVES_FACTURA        la cuota marcada como llave
     RO_T_CASHFLOW_LLAVES_FACTURA_PAGO   en cuantos pagos se paga (opcional)

   UNA CUOTA MARCADA SALE DE ALQUILERES Y NO VUELVE A PROVEEDORES LOCALES: lo
   decide AlquileresFacturas::destino() (A_LLAVES), la misma regla para todos.
   En Llaves reemplaza todo lo proyectado de ese local y ese concepto en esa
   renovacion, y suma como real.

   LA FACTURA NO ES EL PAGO. IRSA factura la cuota inicial una vez y la paga en
   cuotas con echeqs: la cuota se puede repartir en N pagos con fecha e
   importe, como el pago en N filas de Proveedores Locales. Sin pagos, va
   entera en su vencimiento a considerar.

   UNA FACTURA YA PAGADA (de los ultimos 12 meses) TAMBIEN SE MARCA: no suma en
   el horizonte -ya salio la plata-, pero corta la estimacion de esa
   renovacion. PAGADA_AL_MARCAR lo dice.

   ID_CONTRATO_ANT es el contrato QUE SE RENUEVA (la clave de la renovacion en
   LlavesCalculo): sigue valiendo cuando administracion carga el contrato
   nuevo. Para una obra va vacio: la clave es el local y el mes.

   DESHACER ES BAJA LOGICA (VIGENTE = 0, con quien y cuando) de la cuota y de
   sus pagos: la cuota vuelve a Sin imputar en Alquileres. El indice unico
   filtrado impide dos marcas vigentes de la misma cuota.

   LA CLAVE ES LA CUOTA, con los tipos y la collation de CPA04: la misma que la
   del vencimiento a considerar y la exclusion de Alquileres.

   SIN ESTE SCRIPT no se marca nada: el boton no se dibuja, y las facturas
   siguen como hoy (Sin imputar o Excluidas en Alquileres).

   ES REEJECUTABLE: todo pregunta por OBJECT_ID o por sys.indexes. No borra nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_FACTURA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_LLAVES_FACTURA (
        ID                  INT IDENTITY(1,1) NOT NULL,

        COD_PROVEE          VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,
        T_COMP              VARCHAR(3)    COLLATE Latin1_General_BIN NOT NULL,
        N_COMP              VARCHAR(14)   COLLATE Latin1_General_BIN NOT NULL,
        FECHA_VTO_TANGO     DATE          NOT NULL,

        NRO_SUCURS          INT           NOT NULL,
        CONCEPTO            VARCHAR(10)   NOT NULL,
        MES                 CHAR(7)       NOT NULL,
        ID_CONTRATO_ANT     INT           NULL,
        ID_CONTRATO         INT           NULL,

        /* Lo que tenia la cuota al marcarla (el pendiente, o el importe de una
           ya pagada) y la retencion cargada: los pagos suman IMPORTE - RETENCION. */
        IMPORTE             DECIMAL(18,2) NOT NULL,
        RETENCION           DECIMAL(18,2) NULL,
        PAGADA_AL_MARCAR    BIT           NOT NULL CONSTRAINT DF_RO_T_CF_LLFAC_PAGADA DEFAULT (0),
        OBSERVACION         VARCHAR(300)  NULL,

        VIGENTE             BIT           NOT NULL CONSTRAINT DF_RO_T_CF_LLFAC_VIGENTE DEFAULT (1),
        USUARIO_ALTA        VARCHAR(50)   NULL,
        FECHA_ALTA          DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLFAC_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF       VARCHAR(50)   NULL,
        FECHA_MODIF         DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLFAC_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA        VARCHAR(50)   NULL,
        FECHA_BAJA          DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_LLAVES_FACTURA PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_LLFAC_CLAVE
            CHECK (LEN(LTRIM(RTRIM(COD_PROVEE))) > 0 AND LEN(LTRIM(RTRIM(T_COMP))) > 0 AND LEN(LTRIM(RTRIM(N_COMP))) > 0),
        CONSTRAINT CK_RO_T_CF_LLFAC_CONCEPTO CHECK (CONCEPTO IN ('LLAVE', 'OTROS', 'OBRA')),
        CONSTRAINT CK_RO_T_CF_LLFAC_MES CHECK (MES LIKE '[12][0-9][0-9][0-9]-[01][0-9]'),
        CONSTRAINT CK_RO_T_CF_LLFAC_IMPORTES CHECK (IMPORTE >= 0 AND (RETENCION IS NULL OR (RETENCION >= 0 AND RETENCION <= IMPORTE)))
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_LLAVES_FACTURA.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_LLAVES_FACTURA ya existe: no se toca.';
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_RO_T_CF_LLFAC_VIGENTE'
               AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_FACTURA'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_LLFAC_VIGENTE
        ON dbo.RO_T_CASHFLOW_LLAVES_FACTURA (COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO)
        WHERE VIGENTE = 1;

    PRINT 'Creado UX_RO_T_CF_LLFAC_VIGENTE (una marca vigente por cuota).';
END
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_FACTURA_PAGO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_LLAVES_FACTURA_PAGO (
        ID              INT IDENTITY(1,1) NOT NULL,
        ID_FACTURA      INT           NOT NULL,
        ORDEN           TINYINT       NOT NULL,
        FECHA           DATE          NOT NULL,
        IMPORTE         DECIMAL(18,2) NOT NULL,
        VIGENTE         BIT           NOT NULL CONSTRAINT DF_RO_T_CF_LLFPA_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLFPA_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLFPA_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_LLAVES_FACTURA_PAGO PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CF_LLFPA_FACTURA FOREIGN KEY (ID_FACTURA) REFERENCES dbo.RO_T_CASHFLOW_LLAVES_FACTURA (ID),
        CONSTRAINT CK_RO_T_CF_LLFPA_ORDEN CHECK (ORDEN >= 1),
        CONSTRAINT CK_RO_T_CF_LLFPA_IMPORTE CHECK (IMPORTE > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_LLAVES_FACTURA_PAGO.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_LLAVES_FACTURA_PAGO ya existe: no se toca.';
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_RO_T_CF_LLFPA_VIGENTE'
               AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_FACTURA_PAGO'))
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_LLFPA_VIGENTE
        ON dbo.RO_T_CASHFLOW_LLAVES_FACTURA_PAGO (ID_FACTURA, ORDEN) WHERE VIGENTE = 1;
GO

PRINT 'Facturas marcadas como llave listas: se marcan desde Alquileres › Facturas, con el local, el concepto y el mes.';
GO
