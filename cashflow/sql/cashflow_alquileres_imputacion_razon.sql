/* ============================================================================
   MODULO CASHFLOW - ALQUILERES: LA IMPUTACION DE FACTURAS POR RAZON SOCIAL
   (script 60)
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_alquileres_facturas.sql (script 53).
   ----------------------------------------------------------------------------
   QUE HACE

     1. Los TIPOS DE RETENCION de Parametros -> Alquileres (Ganancias, IIBB...).

   ES REEJECUTABLE: todo pregunta por OBJECT_ID, sys.indexes o si la fila ya
   existe.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. LOS TIPOS DE RETENCION

   Una imputacion carga sus retenciones POR TIPO (una linea por tipo). Los
   tipos se dan de alta, se renombran y se dan de baja en Parametros ->
   Alquileres -> Tipos de retencion.

   EL NOMBRE ES UNICO ENTRE LOS VIGENTES SIN DISTINGUIR MAYUSCULAS NI ACENTOS:
   por eso la columna va con Latin1_General_CI_AI y el indice unico filtrado
   la usa. "IIBB CABA" e "iibb caba" son el mismo tipo.

   RENOMBRAR ES DAR DE BAJA Y DAR DE ALTA OTRA FILA, como el resto de
   Parametros: una imputacion vieja guarda el ID del tipo con el que se cargo y
   sigue mostrando ese nombre. UN TIPO DADO DE BAJA no se ofrece para imputar,
   pero las imputaciones que lo usan lo siguen mostrando.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION (
        ID              INT IDENTITY(1,1) NOT NULL,
        NOMBRE          VARCHAR(60)  COLLATE Latin1_General_CI_AI NOT NULL,

        VIGENTE         BIT          NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQTRET_VIGENTE DEFAULT (1),
        /* Si se dio de baja por un cambio de nombre, la fila que la reemplaza. */
        ID_REEMPLAZO    INT          NULL,

        USUARIO_ALTA    VARCHAR(50)  NULL,
        FECHA_ALTA      DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQTRET_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)  NULL,
        FECHA_MODIF     DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQTRET_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)  NULL,
        FECHA_BAJA      DATETIME     NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_TIPO_RETENCION PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_ALQTRET_NOMBRE CHECK (LEN(LTRIM(RTRIM(NOMBRE))) > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION ya existe: no se toca.';
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_RO_T_CF_ALQTRET_VIGENTE'
               AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION'))
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ALQTRET_VIGENTE
        ON dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION (NOMBRE)
        WHERE VIGENTE = 1;
GO

/* La siembra: solo los que no existen vigentes. No pisa un nombre editado ni
   vuelve a dar de alta uno que alguien dio de baja a proposito. */
DECLARE @sembrar TABLE (NOMBRE VARCHAR(60) COLLATE Latin1_General_CI_AI);
INSERT INTO @sembrar (NOMBRE) VALUES ('Ganancias'), ('IIBB Santa Fe'), ('IIBB CABA'), ('IIBB Bs As');

INSERT INTO dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION (NOMBRE, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
SELECT S.NOMBRE, 1, 'script 60', 'script 60'
FROM @sembrar S
WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION T WHERE T.NOMBRE = S.NOMBRE);

PRINT CAST(@@ROWCOUNT AS VARCHAR(10)) + ' tipo(s) de retención sembrado(s).';
GO
