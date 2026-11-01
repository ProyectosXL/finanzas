/* ============================================================================
   MODULO CASHFLOW - TARJETAS: UN RESUMEN PARA VARIAS TARJETAS
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_tarjetas.sql y de
            sql/cashflow_auditoria_usuario.sql. La tabla tiene dos FK, contra el
            maestro y contra los resumenes: sin ellos este script corta con un
            mensaje y no crea nada.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

     RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA   las tarjetas ADICIONALES que
                                              incluye un resumen

   Las tarjetas adicionales de una misma cuenta -mismo banco, mismo vencimiento-
   reciben UN SOLO resumen del banco. Cargarlo en cada tarjeta lo contaria una
   vez por tarjeta en el tablero; cargarlo en una sola dejaria a las otras
   proyectando su estimacion arriba del resumen que ya las incluye.

   ----------------------------------------------------------------------------
   LA TITULAR ES LA DEL RESUMEN, Y ACA VAN SOLO LAS OTRAS

   RO_T_CASHFLOW_TARJETAS_RESUMEN.ID_TARJETA pasa a significar "la tarjeta
   TITULAR": la que lleva el resumen en el tablero, en la serie de su tipo
   (SUPERVISORAS o CORPORATIVAS). Esta tabla guarda solo las ADICIONALES.

   Por eso LOS RESUMENES QUE YA ESTAN NO SE MIGRAN: son resumenes de una sola
   tarjeta, y esa tarjeta es su titular. Sin este script el modulo funciona
   igual, con resumenes de una tarjeta.

   MES SE COPIA DEL RESUMEN, y no es redundancia: es lo que permite que el
   indice unico de abajo diga "una tarjeta no puede estar en dos resumenes
   vigentes del mismo mes". Sin el, una tarjeta podria quedar adicional en dos
   resumenes y su mes se pisaria dos veces.

   ----------------------------------------------------------------------------
   SIN BAJAS FISICAS Y CON AUDITORIA COMPLETA

   Dar de baja el resumen da de baja sus adicionales (VIGENTE = 0, con quien y
   cuando). Sacar una tarjeta de un resumen, tambien. Lo escribe siempre el
   backend con el usuario de la sesion.

   ES REEJECUTABLE: todo pregunta por OBJECT_ID o por sys.indexes y no se
   siembra ninguna fila. No borra nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   0. Guarda dura: sin el maestro ni los resumenes no se crea nada. RAISERROR
      solo no corta los lotes que siguen, asi que el CREATE vuelve a preguntar.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NULL
   OR OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN', 'U') IS NULL
BEGIN
    RAISERROR('Faltan dbo.RO_T_CASHFLOW_TARJETAS o dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN. Corre primero sql/cashflow_tarjetas.sql. No se creo nada.', 16, 1);
END
GO

/* ============================================================================
   1. RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA (
        ID              INT IDENTITY(1,1) NOT NULL,
        ID_RESUMEN      INT          NOT NULL,

        /* Una tarjeta ADICIONAL del resumen. La titular es
           RO_T_CASHFLOW_TARJETAS_RESUMEN.ID_TARJETA y no se repite aca. */
        ID_TARJETA      INT          NOT NULL,

        /* El MES del resumen, copiado: es lo que sostiene el indice unico. */
        MES             CHAR(7)      NOT NULL,

        VIGENTE         BIT          NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJRT_VIGENTE DEFAULT (1),

        USUARIO_ALTA    VARCHAR(50)  NULL,
        FECHA_ALTA      DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJRT_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)  NULL,
        FECHA_MODIF     DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJRT_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)  NULL,
        FECHA_BAJA      DATETIME     NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA PRIMARY KEY CLUSTERED (ID),

        /* Sin ON DELETE: ni los resumenes ni las tarjetas se borran. */
        CONSTRAINT FK_RO_T_CF_TARJRT_RESUMEN FOREIGN KEY (ID_RESUMEN)
            REFERENCES dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN (ID),
        CONSTRAINT FK_RO_T_CF_TARJRT_TARJETA FOREIGN KEY (ID_TARJETA)
            REFERENCES dbo.RO_T_CASHFLOW_TARJETAS (ID),

        CONSTRAINT CK_RO_T_CF_TARJRT_MES
            CHECK (MES LIKE '[12][0-9][0-9][0-9]-[01][0-9]')
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA. Los resumenes que ya estaban '
        + 'no se migran: son de una sola tarjeta, que es su titular.';
END
ELSE IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA', 'U') IS NOT NULL
    PRINT 'dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA ya existe: no se toca.';
GO

/* UNA TARJETA, UN RESUMEN VIGENTE POR MES COMO ADICIONAL. Que no sea ademas
   titular de otro resumen del mismo mes lo valida el backend: son dos tablas y
   un indice no las cruza. */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE name = 'UX_RO_T_CF_TARJRT_VIGENTE'
                     AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_TARJRT_VIGENTE
        ON dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA (ID_TARJETA, MES)
        WHERE VIGENTE = 1;

    PRINT 'Creado UX_RO_T_CF_TARJRT_VIGENTE (una tarjeta, un resumen por mes).';
END
GO

/* La lectura de cada carga: las adicionales vigentes de cada resumen. */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE name = 'IX_RO_T_CF_TARJRT_RESUMEN'
                     AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_TARJRT_RESUMEN
        ON dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA (ID_RESUMEN, VIGENTE)
        INCLUDE (ID_TARJETA, MES);
END
GO

/* ============================================================================
   2. CONTROL: no inserta ni modifica nada.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA', 'U') IS NOT NULL
BEGIN
    DECLARE @compartidos INT = (SELECT COUNT(DISTINCT ID_RESUMEN)
                                FROM dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA
                                WHERE VIGENTE = 1);

    PRINT 'Resumenes compartidos vigentes: ' + CAST(@compartidos AS VARCHAR(10)) + '.';
END
GO
