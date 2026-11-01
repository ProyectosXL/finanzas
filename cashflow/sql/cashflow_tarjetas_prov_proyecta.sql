/* ============================================================================
   MODULO CASHFLOW - TARJETAS PAGOS CORPORATIVOS: PROVEEDORES QUE PROYECTAN
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_tarjetas.sql. No tiene FK: el proveedor es de
            Tango (CPA01), en otra base.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

     RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA   los proveedores cuyas facturas se
                                            repiten como estimacion hasta el fin
                                            del horizonte

   Reemplaza a "marcar mensual" (RO_T_CASHFLOW_TARJETAS_MENSUAL), que marcaba UNA
   factura y guardaba una copia. Ahora se marca el PROVEEDOR en Parametros ->
   Tarjetas, y se repiten las facturas pendientes vinculadas de su ultimo mes
   -ver TarjetasCorporativas::estimacionesPorProveedor()-. La tabla de marcas
   queda como historial y deja de leerse: este script NO la toca.

   UNO VIGENTE POR PROVEEDOR: indice unico filtrado. Darlo de baja es VIGENTE = 0
   con quien y cuando; volver a marcarlo crea una fila nueva.

   La clave lleva Latin1_General_BIN y el largo de CPA01, como el resto de las
   tablas del circuito: sin eso 'OGNUNE' y 'OGNUÑE' serian el mismo proveedor aca
   y dos en Tango.

   ES REEJECUTABLE: todo pregunta por OBJECT_ID o por sys.indexes y no se
   siembra ninguna fila. No borra nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NULL
BEGIN
    RAISERROR('Falta dbo.RO_T_CASHFLOW_TARJETAS. Corre primero sql/cashflow_tarjetas.sql. No se creo nada.', 16, 1);
END
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA (
        ID              INT IDENTITY(1,1) NOT NULL,

        COD_PROVEE      VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,

        /* Copia, para que la marca siga siendo legible si el proveedor deja de
           tener facturas pendientes. */
        RAZON_SOC       VARCHAR(100)  NULL,

        VIGENTE         BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJPP_VIGENTE DEFAULT (1),

        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJPP_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJPP_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA PRIMARY KEY CLUSTERED (ID),

        CONSTRAINT CK_RO_T_CF_TARJPP_COD
            CHECK (LEN(LTRIM(RTRIM(COD_PROVEE))) > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA.';
END
ELSE IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA', 'U') IS NOT NULL
    PRINT 'dbo.RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA ya existe: no se toca.';
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE name = 'UX_RO_T_CF_TARJPP_VIGENTE'
                     AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_TARJPP_VIGENTE
        ON dbo.RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA (COD_PROVEE)
        WHERE VIGENTE = 1;

    PRINT 'Creado UX_RO_T_CF_TARJPP_VIGENTE (uno vigente por proveedor).';
END
GO

/* ============================================================================
   2. LA MIGRACION DE LAS MARCAS MENSUALES

   Los proveedores con una marca mensual vigente (RO_T_CASHFLOW_TARJETAS_MENSUAL,
   VIGENTE = 1) pasan a "proyectan", con USUARIO_ALTA = 'MIGRACION'. La tabla de
   marcas NO SE TOCA: queda como historial.

   'MIGRACION' COMO USUARIO es lo que hace el paso reejecutable y lo deja
   reconocible, como en sql/cashflow_comex_fecha_maestra.sql: un proveedor que ya
   tiene una fila de la migracion -vigente o dada de baja- no se vuelve a migrar.
   Asi, si alguien lo desmarca despues, una segunda corrida no lo revive.

   Al 31/10/2026 son 17 marcas de 16 proveedores (G4S tiene dos), y con la
   regla nueva suman la estimacion que dice README-pagos-tarjetas.md.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_MENSUAL', 'U') IS NOT NULL
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA
        (COD_PROVEE, RAZON_SOC, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
    SELECT m.COD_PROVEE, MAX(m.RAZON_SOC), 1, 'MIGRACION', 'MIGRACION'
    FROM dbo.RO_T_CASHFLOW_TARJETAS_MENSUAL m
    WHERE m.VIGENTE = 1
      AND NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA p
                      WHERE p.COD_PROVEE = m.COD_PROVEE
                        AND (p.VIGENTE = 1 OR p.USUARIO_ALTA = 'MIGRACION'))
    GROUP BY m.COD_PROVEE;

    PRINT 'Proveedores migrados de las marcas mensuales: ' + CAST(@@ROWCOUNT AS VARCHAR(10)) + '.';
END
ELSE IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_MENSUAL', 'U') IS NULL
    PRINT 'No existe RO_T_CASHFLOW_TARJETAS_MENSUAL: no hay marcas que migrar.';
GO

/* 3. Control: no inserta ni modifica nada. */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA', 'U') IS NOT NULL
BEGIN
    SELECT COD_PROVEE, RAZON_SOC, USUARIO_ALTA, FECHA_ALTA
    FROM dbo.RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA
    WHERE VIGENTE = 1
    ORDER BY COD_PROVEE;
END
GO
