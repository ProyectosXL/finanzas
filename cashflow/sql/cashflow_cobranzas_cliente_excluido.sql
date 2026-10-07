/* ============================================================================
   MODULO COBRANZAS FR - EXCLUIR UN CLIENTE DE COBRANZAS FRANQUICIAS
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : en cualquier momento. No depende de ningun otro script: la clave
             es el codigo de cliente de Tango (GVA14.COD_CLIENT).
             Sin el, Cobranzas FR funciona exactamente como hoy -nadie esta
             excluido, que es lo cierto- y la tarjeta de Parametros avisa que
             no se puede excluir.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Hay franquicias cuyas facturas no se van a cobrar por este circuito aunque
   la franquicia siga habilitada en el directorio de sucursales: un cliente en
   gestion judicial, uno que esta refinanciando su deuda por fuera, uno que se
   cobra por otro lado. Hasta ahora la unica forma de sacarlas del tablero era
   inhabilitarlas en el directorio, que es de otra gente y dice otra cosa (si
   el local opera, no si se le cobra).

   ES POR CLIENTE Y ALCANZA A TODAS SUS FACTURAS, las emitidas y las que
   vengan, en las dos solapas de Cobranzas FR -Real a Cobrar y Pendientes
   Proyectados- y en las series del tablero. La toma una persona, con motivo.

   ES INDEPENDIENTE DEL DIRECTORIO: se aplica sobre el universo de franquicias
   habilitadas. Una inhabilitada ya no se trae, asi que no hace falta excluirla.

   ----------------------------------------------------------------------------
   NO CAMBIA EL PPP

   El PPP del grupo empresario sigue contando al cliente excluido: mide como
   paga el grupo, no si se le cobra. Por eso esta tabla no la lee la vista
   RO_V_CASHFLOW_PPP_GRUPO.

   ----------------------------------------------------------------------------
   HISTORIAL, COMO PROVEEDORES Y ECHEQS

   Sin bajas fisicas: volver a incluir marca VIGENTE = 0 y sella quien y
   cuando (USUARIO_BAJA / FECHA_BAJA), y volver a excluir inserta una fila
   nueva. Con FECHA_ALTA sola no se puede distinguir una exclusion que se
   deshizo a los cinco minutos de una que estuvo vigente tres semanas, y la
   segunda es la que explica por que el tablero del mes pasado mostraba otro
   numero. Mismo criterio que RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO y
   RO_T_CASHFLOW_ECHEQ_EXCLUIDO.

   EL MOTIVO ES OBLIGATORIO: sacar plata del tablero sin decir por que no lo
   explica nadie tres meses despues. Lo valida tambien el PHP
   (CobranzasExclusion::validarMotivo), que es donde se puede dar un mensaje;
   el CHECK es la red.

   AUDITORIA: nace con el esquema de sql/cashflow_auditoria_usuario.sql
   (USUARIO_ALTA / FECHA_ALTA, USUARIO_MODIF / FECHA_MODIF, USUARIO_BAJA /
   FECHA_BAJA). Las fechas las pone el servidor; el usuario, el backend.

   COLLATION: el cruce con las facturas de Tango se hace en PHP, nunca con un
   JOIN, asi que la collation de COD_CLIENT no tiene que coincidir con la de
   GVA14.

   El dia que se corre esto la tabla nace vacia y el tablero no se mueve.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho. No borra nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. La tabla.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBRANZAS_CLIENTE_EXCLUIDO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COBRANZAS_CLIENTE_EXCLUIDO (
        ID            INT IDENTITY(1,1) NOT NULL,
        COD_CLIENT    VARCHAR(20)  NOT NULL,
        MOTIVO        VARCHAR(200) NOT NULL,
        VIGENTE       BIT          NOT NULL
            CONSTRAINT DF_RO_T_CF_COBEXC_VIGENTE DEFAULT (1),
        USUARIO_ALTA  VARCHAR(50)  NULL,
        FECHA_ALTA    DATETIME     NULL
            CONSTRAINT DF_RO_T_CF_COBEXC_FALTA DEFAULT (GETDATE()),
        USUARIO_MODIF VARCHAR(50)  NULL,
        FECHA_MODIF   DATETIME     NULL
            CONSTRAINT DF_RO_T_CF_COBEXC_FMODIF DEFAULT (GETDATE()),
        /* Quien y cuando lo volvio a incluir. */
        USUARIO_BAJA  VARCHAR(50)  NULL,
        FECHA_BAJA    DATETIME     NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_COBRANZAS_CLIENTE_EXCLUIDO PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_COBEXC_MOTIVO CHECK (LEN(LTRIM(RTRIM(MOTIVO))) > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_COBRANZAS_CLIENTE_EXCLUIDO.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_COBRANZAS_CLIENTE_EXCLUIDO ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   2. UNA SOLA EXCLUSION VIGENTE POR CLIENTE.

   El historial vive en las filas con VIGENTE = 0; las vigentes tienen que ser
   una por cliente. Va como INDICE UNICO FILTRADO y no como constraint: un
   UNIQUE comun prohibiria tambien las filas historicas repetidas, que es
   justamente lo que el historial necesita tener. Es ademas la red contra dos
   pantallas excluyendo al mismo cliente a la vez: la segunda falla.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'UX_RO_T_CF_COBEXC_VIGENTE'
                 AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_COBRANZAS_CLIENTE_EXCLUIDO'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_COBEXC_VIGENTE
        ON dbo.RO_T_CASHFLOW_COBRANZAS_CLIENTE_EXCLUIDO (COD_CLIENT)
        WHERE VIGENTE = 1;
END
GO

PRINT 'Exclusion de clientes de Cobranzas Franquicias lista.';
GO
