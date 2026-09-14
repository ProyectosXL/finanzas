/* ============================================================================
   CASHFLOW - SALDOS: saldo de caja de locales cargado A MANO
   ============================================================================
   Base: central. Reejecutable.

   POR QUE EXISTE
   --------------
   El saldo de caja de cada local sale de RO_T_SALDOS_CIERRE_SBA29 (servidor
   'locales'), que se alimenta todos los dias. Cuando esa alimentacion falla
   -error de conexion, un cierre que no viajo- el ultimo registro del local
   queda viejo y el tablero proyecta con una caja que no es la de hoy. Esta
   tabla permite tipear el saldo real desde la pestana Saldos -> Saldos Locales.

   COMO SE RESUELVE CONTRA LA CONSULTA (la regla vive en Class/Saldos.php)
   -----------------------------------------------------------------------
   Para cada local se toma el ultimo registro de la consulta y el ultimo saldo
   manual. GANA EL MAS NUEVO POR FECHA_SALDO, y a igual fecha gana el MANUAL:
   si alguien tipeo un saldo es porque el de la consulta no servia. El dia que
   la consulta vuelve a traer un cierre mas nuevo, vuelve a mandar sola, sin
   que nadie tenga que borrar nada.

   FECHA_SALDO de un manual es AYER respecto del dia en que se carga: lo que se
   tipea es el cierre que no llego, y el cierre disponible al dia siguiente es
   el del dia anterior. Es la misma fecha que traeria la consulta si hubiera
   funcionado.

   ES INSERT-ONLY, como todo el modulo: una correccion es una fila nueva con
   ID mayor, y "el ultimo manual" se resuelve por FECHA_SALDO DESC, ID DESC.
   No hay bajas fisicas: ACTIVO = 0.

   Ademas se agrega ORIGEN_DATO a la foto (RO_T_CASHFLOW_SALDOS_LOCAL) para que
   el historico diga si el saldo que entro al tablero ese dia salio de la
   consulta o de una carga manual. Las filas anteriores quedan en 'CONSULTA',
   que es lo unico que existia.

   El mismo bloque va tambien dentro de sql/cashflow_saldos.sql: alcanza con
   correr cualquiera de los dos.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL (
        ID            INT IDENTITY(1,1) NOT NULL,
        NRO_SUCURSAL  INT           NOT NULL,
        FECHA_SALDO   DATE          NOT NULL,
        SALDO_MONEDA  DECIMAL(19,4) NOT NULL,
        /* Lo que la consulta tenia cuando se tipeo el manual, para poder
           explicar despues por que se cargo: fecha vieja o importe distinto. */
        SALDO_CONSULTA DECIMAL(19,4) NULL,
        FECHA_CONSULTA DATE          NULL,
        OBSERVACIONES VARCHAR(500)  NULL,
        ACTIVO        BIT           NOT NULL CONSTRAINT DF_CF_SAL_LOCM_ACTIVO DEFAULT (1),
        FECHA_UPDATE  DATETIME      NOT NULL CONSTRAINT DF_CF_SAL_LOCM_FUPD   DEFAULT (GETDATE()),
        USUARIO       VARCHAR(50)   NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL PRIMARY KEY CLUSTERED (ID)
    );

    /* "El ultimo manual de cada local", que corre en cada dibujado de la
       pestana y en cada calculo del tablero. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL_ULTIMO
        ON dbo.RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL (NRO_SUCURSAL, ACTIVO, FECHA_SALDO DESC, ID DESC)
        INCLUDE (SALDO_MONEDA, FECHA_UPDATE, USUARIO);
END
GO

IF COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_LOCAL', 'ORIGEN_DATO') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_SALDOS_LOCAL
        ADD ORIGEN_DATO VARCHAR(10) NOT NULL
            CONSTRAINT DF_CF_SAL_LOC_ORIGEN DEFAULT ('CONSULTA'),
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_LOCAL_ORIGEN
            CHECK (ORIGEN_DATO IN ('CONSULTA', 'MANUAL'));
END
GO
