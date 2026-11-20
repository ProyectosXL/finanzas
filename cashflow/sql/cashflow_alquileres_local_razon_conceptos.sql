/* ============================================================================
   MODULO CASHFLOW - ALQUILERES: QUE CONCEPTOS FACTURA CADA RAZON SOCIAL DE UN
   LOCAL (script 62)
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_alquileres_local_razones.sql (script 58):
            los conceptos se asignan a una razon social ADICIONAL de un local, y
            las adicionales son de ese script.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

     RO_T_CASHFLOW_ALQ_LOCAL_RAZON_CONCEPTO   los conceptos que factura una
                                              razon social ADICIONAL de un local

   POR DEFECTO TODO VA A LA PRINCIPAL. Aca solo se guarda lo que factura una
   adicional, y esos conceptos SALEN de la principal. San Justo (80): la
   principal, CONTINENTAL URBANA (SASANJ), factura el VMA (8) y el FPC s/VMA
   (9); la adicional, URBANA CENTROS COMERCIALES (SAUCCS), el % s/ventas (6 y 7),
   el FPC s/ventas (16 y 17) y las expensas (11).

   ES LO QUE DECIDE DE QUIEN ES CADA PAGO PROYECTADO: con el dia de vencimiento
   y el diferimiento de que razon social se paga, bajo que razon social se ve en
   la grilla y que pisa una factura imputada de esa razon social. Por eso un
   concepto pertenece a UNA SOLA razon social por local, y lo valida el PHP
   (AlquileresParametros::validarConceptosRazon()).

   LA LISTA VA EN UNA COLUMNA, como los conceptos base de un extraordinario
   (RO_T_CASHFLOW_ALQ_EXTRA.CONCEPTOS): se cambia entera, y cambiarla es dar de
   baja la vigente y dar de alta otra, con quien y cuando. Asi el historial dice
   que facturaba cada razon en cada momento. Una adicional sin conceptos no
   tiene fila vigente: sigue sirviendo para sus extraordinarios.

   QUITAR LA ADICIONAL DEL LOCAL da de baja tambien su fila de aca: sus
   conceptos vuelven a la principal. Si la razon social se da de baja en
   Razones sociales, su fila queda, pero mientras no este dada de alta sus
   conceptos se proyectan con la principal y la pantalla lo avisa: un alquiler
   no desaparece del flujo porque se dio de baja una razon social.

   SIN ESTE SCRIPT todos los conceptos van a la principal, como hasta ahora, y
   el selector de conceptos de Parametros -> Alquileres -> Locales queda
   deshabilitado nombrando el script.

   ES REEJECUTABLE: todo pregunta por OBJECT_ID o por sys.indexes. No borra nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_LOCAL_RAZON_CONCEPTO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_LOCAL_RAZON_CONCEPTO (
        ID              INT IDENTITY(1,1) NOT NULL,

        /* El de la app (RO_T_DETALLE_ALQUILERES.NRO_SUCURS). */
        NRO_SUCURS      INT           NOT NULL,

        /* La razon social adicional (RO_T_CASHFLOW_ALQ_LOCAL_RAZON). */
        COD_PROVEE      VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,

        /* Los ID_CA de la app separados por coma ('6,7,11,16,17'): solo los que
           se proyectan (1, 2, 3, 6, 7, 8, 9, 11, 14, 16 y 17). Lo valida el PHP. */
        CONCEPTOS       VARCHAR(60)   NOT NULL,

        VIGENTE         BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQLRC_VIGENTE DEFAULT (1),

        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQLRC_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQLRC_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_LOCAL_RAZON_CONCEPTO PRIMARY KEY CLUSTERED (ID),

        CONSTRAINT CK_RO_T_CF_ALQLRC_COD
            CHECK (LEN(LTRIM(RTRIM(COD_PROVEE))) > 0),
        CONSTRAINT CK_RO_T_CF_ALQLRC_CONCEPTOS
            CHECK (LEN(LTRIM(RTRIM(CONCEPTOS))) > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_LOCAL_RAZON_CONCEPTO.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_LOCAL_RAZON_CONCEPTO ya existe: no se toca.';
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_LOCAL_RAZON_CONCEPTO', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE name = 'UX_RO_T_CF_ALQLRC_VIGENTE'
                     AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_LOCAL_RAZON_CONCEPTO'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ALQLRC_VIGENTE
        ON dbo.RO_T_CASHFLOW_ALQ_LOCAL_RAZON_CONCEPTO (NRO_SUCURS, COD_PROVEE)
        WHERE VIGENTE = 1;

    PRINT 'Creado UX_RO_T_CF_ALQLRC_VIGENTE (una lista vigente por local y razón social).';
END
GO

PRINT 'Conceptos por razón social listos: por defecto todo sigue en la principal.';
GO
