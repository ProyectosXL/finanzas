/* ============================================================================
   MODULO CASHFLOW - EXCLUIR UN PROVEEDOR DE UN MODULO
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : en cualquier momento. No depende de ningun otro script: la clave
             es el codigo de proveedor de Tango, no una fila del maestro.
             Sin el, Proveedores Locales funciona exactamente como hoy y la
             solapa Maestro avisa que no se puede excluir.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Hay proveedores cuya deuda ya se considera en otra pestana del cashflow -la
   aduana, por ejemplo, en Crono Nacionalizacion- y que ademas aparecen en las
   cuentas a pagar de Tango. Si los dos lados los proyectan, el tablero cuenta
   dos veces la misma plata. La persona que maneja Proveedores Locales sabe
   cuales son; esto le da donde decirlo.

   ES POR PROVEEDOR Y ALCANZA A TODA SU DEUDA, la ya emitida y la que venga.
   Para sacar una factura suelta ya existe la exclusion por factura (ver
   sql/cashflow_prov_locales_excluir_factura.sql).

   ----------------------------------------------------------------------------
   POR QUE UNA TABLA PROPIA Y NO UNA COLUMNA DEL MAESTRO

   El maestro, RO_T_CASHFLOW_PROV_LOCALES_CATEG, es una COPIA REIMPORTABLE de
   la planilla que mantiene administracion, y la reimportacion propone bajas.
   Una columna ahi se perderia en la proxima importacion, o habria que
   agregarla a la planilla, que es de otra gente y no tiene ese concepto.

   Y ademas un proveedor se puede excluir SIN ESTAR EN EL MAESTRO: la deuda de
   un proveedor sin clasificar se proyecta igual, y obligar a clasificarlo para
   poder excluirlo mezcla dos decisiones.

   ----------------------------------------------------------------------------
   POR QUE (COD_PROVEE, MODULO) Y NO UN BIT

   La exclusion es POR MODULO: un proveedor puede estar contado en otra pestana
   para un circuito y no para otro. Hoy el unico modulo es Proveedores Locales,
   pero agregar otro es ampliar el CHECK de MODULO -un ALTER de una linea- y
   escribir su codigo; la tabla no cambia. Un BIT fijo obligaria a una columna
   por modulo.

   MODULO ES UN CHECK Y NO UNA LISTA ADMINISTRABLE a proposito: un modulo nuevo
   siempre viene con codigo que lo lea, asi que no hay nada que un usuario
   pueda dar de alta desde Parametros sin un despliegue. La constante del lado
   PHP es ProveedoresExclusion::MODULOS, y las dos listas se mantienen juntas.

   ----------------------------------------------------------------------------
   HISTORIAL, COMO LA EXCLUSION DE ECHEQS

   Sin bajas fisicas: volver a incluir marca VIGENTE = 0 y sella FECHA_BAJA, y
   volver a excluir inserta una fila nueva. Con FECHA_ALTA sola no se puede
   distinguir una exclusion que se deshizo a los cinco minutos de una que
   estuvo vigente tres semanas, y la segunda es la que explica por que el
   tablero del mes pasado mostraba otro numero. Mismo criterio que
   RO_T_CASHFLOW_ECHEQ_EXCLUIDO.

   EL MOTIVO ES OBLIGATORIO: sacar plata del tablero sin decir por que no lo
   explica nadie tres meses despues. Lo valida tambien el PHP, que es donde se
   puede dar un mensaje; esto es la red.

   ----------------------------------------------------------------------------
   EL IMPORTE NO DESAPARECE: CAMBIA DE SERIE

       PAGOS + PAGOS_FUERA_CRONOGRAMA + PAGOS_EXCLUIDOS_FACTURA
             + PAGOS_EXCLUIDOS_PROVEEDOR = PAGOS_TODO

   El dia que se corre esto la tabla nace vacia y el tablero no se mueve.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. La tabla.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO (
        ID          INT IDENTITY(1,1) NOT NULL,
        /* COLLATE Latin1_General_BIN: la MISMA que CPA01.COD_PROVEE. Sin
           declararla la columna toma la de la base, que es acento-insensible,
           y 'OGNUNE' y 'OGNUÑE' serian el mismo proveedor aca y dos en Tango.
           Ver sql/cashflow_prov_locales_collation.sql. */
        COD_PROVEE  VARCHAR(6)   COLLATE Latin1_General_BIN NOT NULL,
        MODULO      VARCHAR(20)  NOT NULL,
        MOTIVO      VARCHAR(200) NOT NULL,
        VIGENTE     BIT          NOT NULL
            CONSTRAINT DF_RO_T_CF_PROVEXC_VIGENTE DEFAULT (1),
        USUARIO     VARCHAR(50)  NULL,
        FECHA_ALTA  DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_PROVEXC_ALTA DEFAULT (GETDATE()),
        /* Cuando se lo volvio a incluir. */
        FECHA_BAJA  DATETIME     NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_PROVEXC_MODULO CHECK (MODULO IN ('PROV_LOCALES')),
        CONSTRAINT CK_RO_T_CF_PROVEXC_MOTIVO CHECK (LEN(LTRIM(RTRIM(MOTIVO))) > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   2. UNA SOLA EXCLUSION VIGENTE POR PROVEEDOR Y MODULO.

   El historial vive en las filas con VIGENTE = 0; las vigentes tienen que ser
   una por (proveedor, modulo). Va como INDICE UNICO FILTRADO y no como
   constraint: un UNIQUE comun prohibiria tambien las filas historicas
   repetidas, que es justamente lo que el historial necesita tener. Mismo
   criterio que RO_T_CASHFLOW_ECHEQ_EXCLUIDO.

   Es ademas el indice de la consulta de cada carga: "las vigentes de este
   modulo".
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'UX_RO_T_CF_PROVEXC_VIGENTE'
                 AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_PROVEXC_VIGENTE
        ON dbo.RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO (MODULO, COD_PROVEE)
        WHERE VIGENTE = 1;
END
GO

PRINT 'Exclusion de proveedores por modulo lista.';
GO
