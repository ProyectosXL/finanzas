/* ============================================================================
   MODULO CASHFLOW - ALQUILERES: EXCLUIR UNA FACTURA QUE NO SE PAGA POR ACA
   (script 61)
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_alquileres_facturas.sql (script 53). No
            depende del 60: se puede excluir aunque todavia no se impute.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Hay facturas de las razones sociales de alquiler que no se pagan por este
   circuito: los pagos de contrato (la llave, una renovacion) los paga otra
   area. En Alquileres -> Facturas quedaban "Sin imputar" para siempre, con el
   aviso de plata que no suma, y no habia donde decir que no se pagan aca.

     RO_T_CASHFLOW_ALQ_FACTURA_EXCLUIDA   una cuota excluida, con su motivo

   UNA CUOTA EXCLUIDA NO SUMA EN NINGUN LADO: ni en Alquileres, ni en el
   tablero, ni vuelve a Proveedores Locales. Lo decide
   AlquileresFacturas::destino() -la misma regla para todos-, que con la cuota
   en esta tabla la manda a "excluida". Si la condicion de seguridad no se
   cumple, todas las facturas de alquiler -excluidas incluidas- se quedan en
   Proveedores Locales, como siempre: ninguna desaparece del cuadro por un
   script a medio correr.

   EL MOTIVO ES OBLIGATORIO. Lo valida el PHP y el CHECK: una factura sacada del
   cashflow sin motivo no la explica nadie tres meses despues.

   NO SE IMPUTA UNA EXCLUIDA NI SE EXCLUYE UNA IMPUTADA (primero se deshace).
   Lo valida el PHP.

   VOLVER A INCLUIR ES DAR DE BAJA LA EXCLUSION (VIGENTE = 0, con quien y
   cuando), como la exclusion de Proveedores Locales y la de Echeqs: no hay
   bajas fisicas, y el historial dice quien la saco y quien la volvio a poner.
   El indice unico filtrado impide dos exclusiones vigentes de la misma cuota.

   LA CLAVE ES LA CUOTA, con los tipos y la collation de CPA04: la misma que la
   del vencimiento a considerar (RO_T_CASHFLOW_ALQ_FACTURA_VTO).

   SIN ESTE SCRIPT no se excluye nada: el boton Excluir queda deshabilitado
   nombrando el script, y todo lo demas anda.

   ES REEJECUTABLE: todo pregunta por OBJECT_ID o por sys.indexes. No borra nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_FACTURA_EXCLUIDA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_FACTURA_EXCLUIDA (
        ID              INT IDENTITY(1,1) NOT NULL,

        COD_PROVEE      VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,
        T_COMP          VARCHAR(3)    COLLATE Latin1_General_BIN NOT NULL,
        N_COMP          VARCHAR(14)   COLLATE Latin1_General_BIN NOT NULL,
        FECHA_VTO_TANGO DATE          NOT NULL,

        /* El importe que tenia la cuota al excluirla: para el historial y el
           aviso, que no tienen por que ir a buscarla a Tango. */
        IMPORTE         DECIMAL(18,2) NULL,
        MOTIVO          VARCHAR(300)  NOT NULL,

        /* VIGENTE = 0 es "volvio a incluirse": USUARIO_BAJA y FECHA_BAJA dicen
           quien y cuando. */
        VIGENTE         BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQFEX_VIGENTE DEFAULT (1),

        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQFEX_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQFEX_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_FACTURA_EXCLUIDA PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_ALQFEX_CLAVE
            CHECK (LEN(LTRIM(RTRIM(COD_PROVEE))) > 0
               AND LEN(LTRIM(RTRIM(T_COMP)))     > 0
               AND LEN(LTRIM(RTRIM(N_COMP)))     > 0),
        CONSTRAINT CK_RO_T_CF_ALQFEX_MOTIVO CHECK (LEN(LTRIM(RTRIM(MOTIVO))) > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_FACTURA_EXCLUIDA.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_FACTURA_EXCLUIDA ya existe: no se toca.';
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_RO_T_CF_ALQFEX_VIGENTE'
               AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_FACTURA_EXCLUIDA'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ALQFEX_VIGENTE
        ON dbo.RO_T_CASHFLOW_ALQ_FACTURA_EXCLUIDA (COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO)
        WHERE VIGENTE = 1;

    PRINT 'Creado UX_RO_T_CF_ALQFEX_VIGENTE (una exclusion vigente por cuota).';
END
GO

PRINT 'Exclusion de facturas de alquiler lista: se excluyen desde Alquileres › Facturas, con motivo.';
GO
