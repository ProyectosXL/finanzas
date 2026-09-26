/* ============================================================================
   MODULO CASHFLOW - TARJETAS PAGOS CORPORATIVOS: VINCULO Y EXCLUSION
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_tarjetas.sql, que crea
            RO_T_CASHFLOW_TARJETAS. La primera tabla de aca tiene una FK contra
            ella, asi que sin ese script este falla al crearla y lo dice.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

   Dos tablas de la sub-pestana Tarjetas Pagos Corporativos:

     RO_T_CASHFLOW_TARJETAS_FACTURA           que factura se paga con que tarjeta
     RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA  que factura NO entra a esta pestana

   ----------------------------------------------------------------------------
   DE DONDE SALEN LAS FACTURAS: NO DE ACA

   El universo son las facturas pendientes de Tango de los proveedores cuya forma
   de pago VIGENTE es 'TARJETA CORP', y se leen reutilizando
   Proveedores::getPendientes() -la misma consulta de Cuentas a Pagar Locales-,
   sin una segunda consulta a CPA04/CPA54. Estas dos tablas solo guardan las dos
   decisiones que una persona toma encima de ese listado.

   Al 26/09/2026 son 135 vencimientos por $ 89.896.563,43 en 94 comprobantes.

   VIGENTE quiere decir: el override por factura de Proveedores Locales
   (RO_T_CASHFLOW_PROV_LOCALES_PAGO.FORMA_PAGO_CRONOGRAMA) si lo hay, y si no la
   forma del maestro. Hoy hay exactamente una factura que entra por el override:
   OGHALL FAC A0000100008942.

   ----------------------------------------------------------------------------
   LA CLAVE ES EL COMPROBANTE, LA MISMA QUE PROVEEDORES LOCALES

   (COD_PROVEE, T_COMP, N_COMP), identica a Proveedores::clavePago() y a
   RO_T_CASHFLOW_PROV_LOCALES_PAGO. NO incluye el vencimiento, y eso es una
   decision:

     - La tarjeta con la que se paga una factura es una propiedad de LA FACTURA,
       no de cada cuota. Agregar FECHA_VTO a la clave habilitaria vincular la
       cuota 3 a una tarjeta y la 4 a otra, que no describe nada real.
     - Las tres tablas de este circuito y la de Proveedores Locales comparten una
       sola clave, asi que un comprobante se identifica igual en todas.

   Un comprobante con varias cuotas queda vinculado entero y cada cuota genera su
   cobertura en el mes de SU vencimiento. En el universo de tarjeta corporativa
   los comprobantes en cuotas son los seguros (OGRSA), que no son los que se
   vinculan a una tarjeta: lo que se vincula es de una sola cuota.

   ----------------------------------------------------------------------------
   LA COLLATION NO ES UN DETALLE

   Las tres columnas declaran Latin1_General_BIN, la MISMA que CPA04 y que
   RO_T_CASHFLOW_PROV_LOCALES_PAGO. Sin declararla la columna toma la de la base
   -acento insensible- y 'OGNUNE' y 'OGNUÑE' serian el mismo proveedor aca y dos
   distintos en Tango. Hay 27 codigos con caracteres no ASCII. Ver
   sql/cashflow_prov_locales_collation.sql.

   Los largos tambien son los de alla: VARCHAR(6), VARCHAR(3) y VARCHAR(14).

   ----------------------------------------------------------------------------
   LA EXCLUSION ES DE ESTA PESTANA, Y NO TOCA LA DE PROVEEDORES LOCALES

   Son dos decisiones distintas sobre la misma factura:

     RO_T_CASHFLOW_PROV_LOCALES_PAGO.EXCLUIDA   "no entra a Cuentas a Pagar
                                                 Locales"
     esta tabla                                 "no entra a Tarjetas Pagos
                                                 Corporativos"

   Una tabla compartida obligaria a que excluir de una pestana excluyera de la
   otra, que es exactamente lo que no se quiere: hoy estas facturas viven en las
   dos, en series que no conviven en el tablero.

   EL MOTIVO ES OBLIGATORIO -lo exige el CHECK y lo vuelve a exigir el endpoint,
   que es alcanzable sin pasar por la pantalla- y NO HAY BAJAS FISICAS: volver a
   incluir marca VIGENTE = 0 y sella FECHA_BAJA. Con un DELETE, "esta factura
   nunca se excluyo" y "se excluyo y se volvio atras" son indistinguibles despues
   del hecho. Mismo criterio que RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO y
   RO_T_CASHFLOW_ECHEQ_EXCLUIDO.

   ----------------------------------------------------------------------------
   EL DOBLE CONTEO CON LOS COMPROBANTES DE TARJETA DE SUPERVISORAS

   Los gastos con tarjeta de las supervisoras se estiman en la sub-pestana Gastos
   Supervisoras a partir de RO_T_GASTOS_SUPERVISION, y los comprobantes de esos
   mismos gastos pueden estar cargados en Tango como facturas de un proveedor con
   forma de pago TARJETA CORP. Cuando eso pasa, el mismo peso entra dos veces.

   NO SE RESUELVE POR CODIGO, y es deliberado: decidir cual de las dos puntas es
   la buena para un comprobante concreto es mirar el comprobante, no una regla.
   Se maneja excluyendo esas facturas con esta tabla, y se evalua en produccion.
   Ver README-pagos-tarjetas.md.

   ----------------------------------------------------------------------------
   SI NO SE CORRE

   La sub-pestana Tarjetas Pagos Corporativos SE LEE IGUAL: el listado sale de
   Tango y no depende de estas tablas. Lo que no se puede es vincular una factura
   a una tarjeta ni excluir ninguna, asi que los botones de la barra de seleccion
   se dibujan apagados nombrando este archivo, no hay ninguna cobertura -que se
   calcula solo sobre las vinculadas- y nadie esta excluido, que es lo cierto.

   El tablero tampoco cambia: las dos tablas nacen vacias.

   ES REEJECUTABLE: todo pregunta por OBJECT_ID o por sys.indexes y no se siembra
   ninguna fila.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   0. Guarda dura: sin el maestro de tarjetas, la FK de la primera tabla no se
      puede crear. Se corta acá con un mensaje que dice que correr, en vez de
      dejar media instalacion.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NULL
BEGIN
    RAISERROR('Falta dbo.RO_T_CASHFLOW_TARJETAS. Corre primero sql/cashflow_tarjetas.sql: el vinculo factura-tarjeta tiene una FK contra ella.', 16, 1);
END
GO

/* ----------------------------------------------------------------------------
   0b. Guardas blandas: avisan y no impiden nada.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.CPA04', 'U') IS NULL
BEGIN
    PRINT 'AVISO: no existe dbo.CPA04. Las tablas se crean igual, pero no va a haber facturas '
        + 'pendientes que vincular: el listado sale de ahi.';
END
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO', 'U') IS NULL
BEGIN
    PRINT 'AVISO: no existe dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO, la tabla de overrides de '
        + 'Proveedores Locales. Las tablas se crean igual, pero el universo va a salir SOLO de '
        + 'la forma del maestro: las facturas que entran por el override por factura no se van '
        + 'a ver. Corre sql/cashflow_prov_locales.sql y '
        + 'sql/cashflow_prov_locales_forma_por_factura.sql.';
END
GO

/* ============================================================================
   1. RO_T_CASHFLOW_TARJETAS_FACTURA
      Que factura se paga con que tarjeta.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_FACTURA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_TARJETAS_FACTURA (
        ID            INT IDENTITY(1,1) NOT NULL,

        /* La clave del comprobante, con los tipos y la collation de CPA04. Ver
           la nota del encabezado. */
        COD_PROVEE    VARCHAR(6)  COLLATE Latin1_General_BIN NOT NULL,
        T_COMP        VARCHAR(3)  COLLATE Latin1_General_BIN NOT NULL,
        N_COMP        VARCHAR(14) COLLATE Latin1_General_BIN NOT NULL,

        ID_TARJETA    INT         NOT NULL,

        /* Baja logica: desvincular marca ACTIVO = 0 y deja la fila. El historial
           es lo que despues explica por que una factura figuraba en otra tarjeta. */
        ACTIVO        BIT         NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJFAC_ACTIVO DEFAULT (1),

        USUARIO_ALTA  VARCHAR(50) NULL,
        FECHA_ALTA    DATETIME    NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJFAC_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF VARCHAR(50) NULL,
        FECHA_MODIF   DATETIME    NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJFAC_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA  VARCHAR(50) NULL,
        FECHA_BAJA    DATETIME    NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_TARJETAS_FACTURA PRIMARY KEY CLUSTERED (ID),

        CONSTRAINT FK_RO_T_CF_TARJFAC_TARJETA FOREIGN KEY (ID_TARJETA)
            REFERENCES dbo.RO_T_CASHFLOW_TARJETAS (ID),

        /* El comprobante tiene que estar identificado. Las tres partes hacen
           falta: el numero de comprobante lo pone el proveedor y se repite entre
           proveedores -hay 10.293 pares (T_COMP, N_COMP) repetidos-, asi que sin
           el codigo la clave no identifica nada. */
        CONSTRAINT CK_RO_T_CF_TARJFAC_CLAVE
            CHECK (LEN(LTRIM(RTRIM(COD_PROVEE))) > 0
               AND LEN(LTRIM(RTRIM(T_COMP)))     > 0
               AND LEN(LTRIM(RTRIM(N_COMP)))     > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_TARJETAS_FACTURA.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_TARJETAS_FACTURA ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   1b. UN COMPROBANTE, UNA TARJETA. Filtrado por ACTIVO = 1: el historial son las
       filas con ACTIVO = 0, y un UNIQUE comun prohibiria justo las repetidas que
       el historial necesita tener.

       Es la red contra dos pantallas vinculando a la vez: la segunda falla en
       vez de dejar la factura en dos tarjetas, que duplicaria su cobertura.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'UX_RO_T_CF_TARJFAC_VIGENTE'
                 AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_FACTURA'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_TARJFAC_VIGENTE
        ON dbo.RO_T_CASHFLOW_TARJETAS_FACTURA (COD_PROVEE, T_COMP, N_COMP)
        WHERE ACTIVO = 1;

    PRINT 'Creado UX_RO_T_CF_TARJFAC_VIGENTE (un comprobante, una tarjeta).';
END
GO

/* ----------------------------------------------------------------------------
   1c. El otro lado de la consulta: "que facturas tiene vinculadas esta tarjeta",
       que es con lo que se calcula la cobertura de cada mes.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'IX_RO_T_CF_TARJFAC_TARJETA'
                 AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_FACTURA'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_TARJFAC_TARJETA
        ON dbo.RO_T_CASHFLOW_TARJETAS_FACTURA (ID_TARJETA, ACTIVO)
        INCLUDE (COD_PROVEE, T_COMP, N_COMP);
END
GO

/* ============================================================================
   2. RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA
      Que factura NO entra a esta pestana, y por que.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA (
        ID            INT IDENTITY(1,1) NOT NULL,

        /* La MISMA clave que el vinculo y que Proveedores Locales. */
        COD_PROVEE    VARCHAR(6)   COLLATE Latin1_General_BIN NOT NULL,
        T_COMP        VARCHAR(3)   COLLATE Latin1_General_BIN NOT NULL,
        N_COMP        VARCHAR(14)  COLLATE Latin1_General_BIN NOT NULL,

        /* OBLIGATORIO. Sacar plata del tablero sin decir por que no lo explica
           nadie tres meses despues. */
        MOTIVO        VARCHAR(200) NOT NULL,

        /* El historial: volver a incluir marca VIGENTE = 0 y sella FECHA_BAJA.
           Se llama VIGENTE y no ACTIVO igual que RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO
           y RO_T_CASHFLOW_ECHEQ_EXCLUIDO, que son las otras dos tablas de
           exclusion del modulo. */
        VIGENTE       BIT          NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJEXC_VIGENTE DEFAULT (1),

        USUARIO_ALTA  VARCHAR(50)  NULL,
        FECHA_ALTA    DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJEXC_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF VARCHAR(50)  NULL,
        FECHA_MODIF   DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJEXC_MODIF DEFAULT (GETDATE()),

        /* Quien la volvio a incluir y cuando. USUARIO_ALTA no se pisa: dice
           quien excluyo, que es otra persona y otra decision. */
        USUARIO_BAJA  VARCHAR(50)  NULL,
        FECHA_BAJA    DATETIME     NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA PRIMARY KEY CLUSTERED (ID),

        CONSTRAINT CK_RO_T_CF_TARJEXC_CLAVE
            CHECK (LEN(LTRIM(RTRIM(COD_PROVEE))) > 0
               AND LEN(LTRIM(RTRIM(T_COMP)))     > 0
               AND LEN(LTRIM(RTRIM(N_COMP)))     > 0),

        CONSTRAINT CK_RO_T_CF_TARJEXC_MOTIVO
            CHECK (LEN(LTRIM(RTRIM(MOTIVO))) > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   2b. UNA SOLA EXCLUSION VIGENTE POR COMPROBANTE. Filtrado por VIGENTE = 1, por
       el mismo motivo que arriba: es la red contra dos pantallas excluyendo a la
       vez, y el historial necesita poder repetir la clave.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'UX_RO_T_CF_TARJEXC_VIGENTE'
                 AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_TARJEXC_VIGENTE
        ON dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA (COD_PROVEE, T_COMP, N_COMP)
        WHERE VIGENTE = 1;

    PRINT 'Creado UX_RO_T_CF_TARJEXC_VIGENTE (una exclusion vigente por comprobante).';
END
GO

/* ============================================================================
   3. CONTROLES: COMO QUEDO. No insertan ni modifican nada.
   ============================================================================ */
DECLARE @vinculos INT = (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_TARJETAS_FACTURA
                         WHERE ACTIVO = 1);
DECLARE @excluidas INT = (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA
                          WHERE VIGENTE = 1);

PRINT 'Facturas vinculadas a una tarjeta: ' + CAST(@vinculos AS VARCHAR(10)) + '.';
PRINT 'Facturas excluidas de esta pestana: ' + CAST(@excluidas AS VARCHAR(10)) + '.';

/* Una factura vinculada Y excluida a la vez no es un error: la exclusion gana y
   se avisa. Se imprime porque es una combinacion que conviene mirar -alguien
   vinculo y otro excluyo- y no se corrige sola. */
DECLARE @ambas INT = (
    SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_TARJETAS_FACTURA v
    WHERE v.ACTIVO = 1
      AND EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA e
                  WHERE e.VIGENTE = 1
                    AND e.COD_PROVEE = v.COD_PROVEE
                    AND e.T_COMP     = v.T_COMP
                    AND e.N_COMP     = v.N_COMP));

IF @ambas > 0
    PRINT 'AVISO: ' + CAST(@ambas AS VARCHAR(10)) + ' factura(s) estan vinculadas a una tarjeta '
        + 'Y excluidas de la pestana. Gana la exclusion: no suman a la fila del tablero ni '
        + 'generan cobertura, y la pestana lo dice.';
GO

PRINT 'Vinculo y exclusion de facturas de tarjeta listos.';
GO
