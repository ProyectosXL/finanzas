/* ============================================================================
   MODULO CASHFLOW - ALQUILERES: LAS FACTURAS REALES (script 53)
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : DESPUES de sql/cashflow_alquileres.sql (Parametros -> Alquileres).
             No es una dependencia tecnica -las tablas se crean igual- pero sin
             razones sociales dadas de alta no hay ninguna factura de alquiler.
             ANTES de sql/cashflow_alquileres_fila.sql: ver "LA CONDICION DE
             SEGURIDAD", abajo.
   ----------------------------------------------------------------------------
   QUE GUARDA

   Las facturas pendientes de Tango de los proveedores dados de alta en
   Parametros -> Alquileres se gestionan en la pestaña Alquileres -> Facturas.
   Las facturas NO SE COPIAN: salen de Proveedores::getPendientes(), la misma
   lectura de Tango que usa Proveedores Locales. Aca se guarda:

     RO_T_CASHFLOW_ALQ_FACTURA_VTO            el vencimiento a considerar, por
                                              cuota, cuando no es el de Tango

   LA IMPUTACION YA NO ES DE ESTE SCRIPT. Hasta el 20/11/2026 creaba tambien la
   imputacion por renglones (RO_T_CASHFLOW_ALQ_IMPUTACION, _IMP_FACTURA,
   _IMP_RENGLON, _IMP_RENGLON_CONCEPTO e _IMP_RENGLON_LOCAL). La reemplazo la
   imputacion por razon social del script 60
   (sql/cashflow_alquileres_imputacion_razon.sql), que borra esas cinco tablas:
   si este script las siguiera creando, correrlo de nuevo las haria volver.

   ----------------------------------------------------------------------------
   LA CONDICION DE SEGURIDAD

   Las facturas de alquiler salen de Proveedores Locales SOLO si existe la
   tabla de este script Y hay una fila activa del tablero con ALQUILERES /
   REAL o PAGOS. Sin este script se quedan en Proveedores Locales como siempre,
   y las pantallas lo avisan en critico. Ver cashflow/Class/AlquileresFacturas.php.
   Es la tabla del vencimiento -y no la de las imputaciones- porque es la que
   este script sigue creando: sin las del script 60 las facturas quedan en
   Alquileres sin imputar (no suman) y la pantalla dice que falta.

   EL DIA QUE SE CORRE, si la fila 16 del tablero sigue activa (ALQUILERES /
   PAGOS), las facturas pasan a Alquileres y quedan "sin imputar": no suman
   hasta que se imputen, y el aviso lo dice con el importe.

   ES REEJECUTABLE: cada tabla e indice pregunta si ya existe.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. El vencimiento a considerar, por cuota. Mismo patron que
      RO_T_CASHFLOW_TARJETAS_FACTURA_VTO.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_FACTURA_VTO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_FACTURA_VTO (
        ID              INT IDENTITY(1,1) NOT NULL,

        /* La cuota, con los tipos y la collation de CPA04. */
        COD_PROVEE      VARCHAR(6)   COLLATE Latin1_General_BIN NOT NULL,
        T_COMP          VARCHAR(3)   COLLATE Latin1_General_BIN NOT NULL,
        N_COMP          VARCHAR(14)  COLLATE Latin1_General_BIN NOT NULL,
        FECHA_VTO_TANGO DATE         NOT NULL,

        /* El que vale. LA FECHA MINIMA ES HOY, y la valida el PHP. */
        FECHA_VTO       DATE         NOT NULL,
        MOTIVO          VARCHAR(300) NULL,

        VIGENTE         BIT          NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQVTO_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)  NULL,
        FECHA_ALTA      DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQVTO_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)  NULL,
        FECHA_MODIF     DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQVTO_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)  NULL,
        FECHA_BAJA      DATETIME     NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_FACTURA_VTO PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_ALQVTO_CLAVE
            CHECK (LEN(LTRIM(RTRIM(COD_PROVEE))) > 0
               AND LEN(LTRIM(RTRIM(T_COMP)))     > 0
               AND LEN(LTRIM(RTRIM(N_COMP)))     > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_FACTURA_VTO.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_FACTURA_VTO ya existe: no se toca.';
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_RO_T_CF_ALQVTO_VIGENTE'
               AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_FACTURA_VTO'))
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ALQVTO_VIGENTE
        ON dbo.RO_T_CASHFLOW_ALQ_FACTURA_VTO (COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO)
        WHERE VIGENTE = 1;
GO

PRINT 'Facturas de alquiler listas. Las facturas de las razones dadas de alta pasan a la '
    + 'pestaña Alquileres cuando el tablero tenga una fila activa ALQUILERES / REAL o PAGOS.';
GO
