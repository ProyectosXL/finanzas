/* ============================================================================
   MODULO CASHFLOW - ALQUILERES: MAS DE UNA RAZON SOCIAL POR LOCAL
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_alquileres.sql (script 51)
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

     RO_T_CASHFLOW_ALQ_LOCAL_RAZON    las razones sociales ADICIONALES de un
                                      local: las que le facturan algo ademas de
                                      la principal

   Un local puede tener facturas de mas de una razon social. San Justo (80):
   CONTINENTAL URBANA (SASANJ) factura el alquiler y URBANA CENTROS COMERCIALES
   (SAUCCS) las expensas y el fondo de promocion.

   LA PRINCIPAL SIGUE EN RO_T_CASHFLOW_ALQ_LOCAL.COD_PROVEE y es la que manda en
   la proyeccion: el dia de vencimiento y los meses de diferimiento del local son
   los suyos. Una adicional no cambia ninguna fecha proyectada: sirve para que
   sus facturas se imputen a ese local y para que sus pagos extraordinarios lo
   alcancen. Por eso no se migra nada: sin filas aca, todo sigue como hoy.

   UNA FILA VIGENTE POR (LOCAL, RAZON SOCIAL), CON HISTORIAL. Quitar una
   adicional es VIGENTE = 0 con quien y cuando. No hay bajas fisicas.

   COD_PROVEE lleva Latin1_General_BIN y el largo de CPA01, como en el resto
   de las tablas de alquileres.

   ES REEJECUTABLE: todo pregunta por OBJECT_ID o por sys.indexes. No borra nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_LOCAL_RAZON', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_LOCAL_RAZON (
        ID              INT IDENTITY(1,1) NOT NULL,

        /* El de la app (RO_T_DETALLE_ALQUILERES.NRO_SUCURS). */
        NRO_SUCURS      INT           NOT NULL,

        COD_PROVEE      VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,

        VIGENTE         BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQLRZ_VIGENTE DEFAULT (1),

        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQLRZ_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQLRZ_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_LOCAL_RAZON PRIMARY KEY CLUSTERED (ID),

        CONSTRAINT CK_RO_T_CF_ALQLRZ_COD
            CHECK (LEN(LTRIM(RTRIM(COD_PROVEE))) > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_LOCAL_RAZON.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_LOCAL_RAZON ya existe: no se toca.';
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_LOCAL_RAZON', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE name = 'UX_RO_T_CF_ALQLRZ_VIGENTE'
                     AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_LOCAL_RAZON'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ALQLRZ_VIGENTE
        ON dbo.RO_T_CASHFLOW_ALQ_LOCAL_RAZON (NRO_SUCURS, COD_PROVEE)
        WHERE VIGENTE = 1;

    PRINT 'Creado UX_RO_T_CF_ALQLRZ_VIGENTE (una vigente por local y razón social).';
END
GO
