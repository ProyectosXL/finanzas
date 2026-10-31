/* ============================================================================
   CASHFLOW - VENTAS: EL MIX DE COBRO POR MES DEL ANIO
   ============================================================================
   Base    : central. Reejecutable: cada tabla se crea solo si no existe, y no
             hay DROP ni migracion de datos.
   Orden   : DESPUES de sql/cashflow_ventas_mix_nodo.sql (las FK apuntan a
             RO_T_CASHFLOW_VENTAS_MIX_NODO) y EN EL MISMO MOMENTO de publicar
             el codigo de feature/mayoristas-vendedora-mix-mensual. Ver
             README-ventas.md, "Orden de ejecucion de los scripts".
   ----------------------------------------------------------------------------
   POR QUE EXISTE
   --------------
   Cada canal tenia UN arbol de mix para todos los meses. La cobranza tiene
   estacionalidad -en diciembre se vende mas con credito y en cuotas que en
   febrero- y un solo mix la aplana. Ahora cada canal elige:

     CONSTANTE  como hasta ahora: el arbol de RO_T_CASHFLOW_VENTAS_MIX_NODO
                para todos los meses.
     VARIABLE   el MISMO arbol, con porcentaje, costo, tasa, dias y activo
                propios para los meses que se cargaron. Un mes sin mix propio
                usa el constante.

   ES MES DEL ANIO, NO MES PUNTUAL: el mix de "diciembre" vale para todos los
   diciembres del horizonte. Y el mes que decide es el de la VENTA, no el de
   la acreditacion.

   LA ESTRUCTURA NO SE DUPLICA. Padre, nivel, nombre y orden siguen viviendo
   solo en RO_T_CASHFLOW_VENTAS_MIX_NODO: un mes guarda valores, no nodos. Si
   guardara su propio arbol, renombrar "Credito" en el constante dejaria doce
   "Credito" viejos dando vueltas.

   La regla que combina el constante con el mes vive en UN solo lugar:
   MixCobro::resolver(), en cashflow/Class/MixCobro.php. Este script no
   resuelve nada.

   QUE CREA, EN ESTE ORDEN

     1. RO_T_CASHFLOW_VENTAS_MIX_CANAL     el modo de cada canal
     2. RO_T_CASHFLOW_VENTAS_MIX_MES       que meses tienen mix propio
     3. RO_T_CASHFLOW_VENTAS_MIX_NODO_MES  los valores de cada nodo en ese mes

   INVARIANTE: APENAS SE CORRE, LA PROYECCION DA EXACTAMENTE LO MISMO QUE
   ANTES. Las tres tablas nacen vacias, y un canal sin fila en la primera es
   CONSTANTE. Ver README-ventas.md, "El invariante del mix por mes".

   SIN ESTE SCRIPT la pantalla no se cae: todos los canales quedan en
   constante, y Parametros muestra el selector de modo deshabilitado diciendo
   que script falta.

   SIN BAJAS FISICAS. Volver un canal a CONSTANTE no borra sus meses: quedan
   guardados y vuelven si el canal se pasa otra vez a VARIABLE. Y "Usar el
   constante en este mes" da de baja el mes (ACTIVO = 0, con USUARIO_BAJA y
   FECHA_BAJA), sin borrar sus valores.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO', 'U') IS NULL
BEGIN
    RAISERROR('Falta RO_T_CASHFLOW_VENTAS_MIX_NODO: corre antes sql/cashflow_ventas_mix_nodo.sql.', 16, 1);
    SET NOEXEC ON;
END
GO

/* ----------------------------------------------------------------------------
   1. RO_T_CASHFLOW_VENTAS_MIX_CANAL
   El modo de cada canal. UN CANAL SIN FILA ES CONSTANTE: es lo que hace que
   la tabla pueda nacer vacia sin cambiar la proyeccion.

   La lista de canales no tiene CHECK: es Parametros::CANALES, del codigo, y
   el PHP la valida antes de escribir, igual que en MIX_NODO.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_MIX_CANAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_VENTAS_MIX_CANAL (
        CANAL             VARCHAR(20)   NOT NULL,
        MODO              VARCHAR(10)   NOT NULL CONSTRAINT DF_CF_VTA_MIXC_MODO DEFAULT ('CONSTANTE'),

        USUARIO_ALTA      VARCHAR(50)   NULL,
        FECHA_ALTA        DATETIME      NULL CONSTRAINT DF_CF_VTA_MIXC_FALTA  DEFAULT (GETDATE()),
        USUARIO_MODIF     VARCHAR(50)   NULL,
        FECHA_MODIF       DATETIME      NULL CONSTRAINT DF_CF_VTA_MIXC_FMODIF DEFAULT (GETDATE()),
        USUARIO_BAJA      VARCHAR(50)   NULL,
        FECHA_BAJA        DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_VENTAS_MIX_CANAL PRIMARY KEY CLUSTERED (CANAL),
        CONSTRAINT CK_RO_T_CASHFLOW_VENTAS_MIX_CANAL_MODO
            CHECK (MODO IN ('CONSTANTE', 'VARIABLE'))
    );

    PRINT 'Creada RO_T_CASHFLOW_VENTAS_MIX_CANAL.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_VENTAS_MIX_CANAL ya existia: no se toco.';
GO

/* ----------------------------------------------------------------------------
   2. RO_T_CASHFLOW_VENTAS_MIX_MES
   Una fila por canal y mes del anio que tiene (o tuvo) mix propio.

   ACTIVO = 1 es "este mes usa sus propios valores". ACTIVO = 0 es "Usar el
   constante en este mes": la fila y sus valores quedan, con quien y cuando lo
   dio de baja, y si el mes se vuelve a cargar se reactiva la MISMA fila. Por
   eso la unicidad es (CANAL, MES) sin mirar ACTIVO.

   Que el canal este en VARIABLE es otra cosa y vive en MIX_CANAL: un mes
   activo de un canal en CONSTANTE se guarda pero no se usa.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_MIX_MES', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_VENTAS_MIX_MES (
        ID                INT IDENTITY(1,1) NOT NULL,
        CANAL             VARCHAR(20)   NOT NULL,
        MES               TINYINT       NOT NULL,
        ACTIVO            BIT           NOT NULL CONSTRAINT DF_CF_VTA_MIXM_ACTIVO DEFAULT (1),

        USUARIO_ALTA      VARCHAR(50)   NULL,
        FECHA_ALTA        DATETIME      NULL CONSTRAINT DF_CF_VTA_MIXM_FALTA  DEFAULT (GETDATE()),
        USUARIO_MODIF     VARCHAR(50)   NULL,
        FECHA_MODIF       DATETIME      NULL CONSTRAINT DF_CF_VTA_MIXM_FMODIF DEFAULT (GETDATE()),
        USUARIO_BAJA      VARCHAR(50)   NULL,
        FECHA_BAJA        DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_VENTAS_MIX_MES PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT UQ_RO_T_CASHFLOW_VENTAS_MIX_MES UNIQUE (CANAL, MES),
        CONSTRAINT CK_RO_T_CASHFLOW_VENTAS_MIX_MES_MES CHECK (MES BETWEEN 1 AND 12)
    );

    PRINT 'Creada RO_T_CASHFLOW_VENTAS_MIX_MES.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_VENTAS_MIX_MES ya existia: no se toco.';
GO

/* ----------------------------------------------------------------------------
   3. RO_T_CASHFLOW_VENTAS_MIX_NODO_MES
   Los valores de un nodo en un mes: lo mismo que se edita de un nodo en el
   constante, menos la estructura. Mismos rangos y mismo significado de NULL
   que en MIX_NODO: costo y tasa NULL valen cero, dias NULL es "los define un
   nivel de arriba".

   ACTIVO VARIA POR MES: apagar "Go Cuotas" solo en febrero es un caso de
   negocio. Inhabilitar un nodo en el constante no lo apaga aca solo; la
   pantalla ofrece hacerlo, en la misma transaccion.

   Un nodo nuevo entra con una fila en cada mes cargado de su canal,
   inhabilitado y en 0%, como en el constante. Si igual faltara una fila -una
   edicion directa en la base-, el nodo toma los valores del constante y la
   validacion del mes lo marca.

   La FK a MIX_NODO es la que impide valores de un nodo que no existe. Que el
   nodo sea del MISMO canal que el mes lo valida el PHP: un CHECK no puede
   mirar otra fila.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO_MES', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO_MES (
        ID                INT IDENTITY(1,1) NOT NULL,
        ID_MIX_MES        INT           NOT NULL,
        ID_NODO           INT           NOT NULL,
        PORCENTAJE        DECIMAL(12,6) NOT NULL CONSTRAINT DF_CF_VTA_MIXNM_PORC   DEFAULT (0),
        COSTO             DECIMAL(12,6) NULL,
        TASA              DECIMAL(12,6) NULL,
        DIAS_ACREDITACION INT           NULL,
        ACTIVO            BIT           NOT NULL CONSTRAINT DF_CF_VTA_MIXNM_ACTIVO DEFAULT (0),

        USUARIO_ALTA      VARCHAR(50)   NULL,
        FECHA_ALTA        DATETIME      NULL CONSTRAINT DF_CF_VTA_MIXNM_FALTA  DEFAULT (GETDATE()),
        USUARIO_MODIF     VARCHAR(50)   NULL,
        FECHA_MODIF       DATETIME      NULL CONSTRAINT DF_CF_VTA_MIXNM_FMODIF DEFAULT (GETDATE()),
        USUARIO_BAJA      VARCHAR(50)   NULL,
        FECHA_BAJA        DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_VENTAS_MIX_NODO_MES PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CASHFLOW_VENTAS_MIX_NODO_MES_MES
            FOREIGN KEY (ID_MIX_MES) REFERENCES dbo.RO_T_CASHFLOW_VENTAS_MIX_MES (ID),
        CONSTRAINT FK_RO_T_CASHFLOW_VENTAS_MIX_NODO_MES_NODO
            FOREIGN KEY (ID_NODO) REFERENCES dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO (ID),
        CONSTRAINT UQ_RO_T_CASHFLOW_VENTAS_MIX_NODO_MES UNIQUE (ID_MIX_MES, ID_NODO),
        CONSTRAINT CK_RO_T_CASHFLOW_VENTAS_MIX_NODO_MES_PORC
            CHECK (PORCENTAJE >= 0 AND PORCENTAJE <= 1),
        CONSTRAINT CK_RO_T_CASHFLOW_VENTAS_MIX_NODO_MES_COSTO
            CHECK (COSTO IS NULL OR (COSTO >= 0 AND COSTO <= 1)),
        CONSTRAINT CK_RO_T_CASHFLOW_VENTAS_MIX_NODO_MES_TASA
            CHECK (TASA IS NULL OR (TASA >= 0 AND TASA <= 1)),
        CONSTRAINT CK_RO_T_CASHFLOW_VENTAS_MIX_NODO_MES_DIAS
            CHECK (DIAS_ACREDITACION IS NULL OR DIAS_ACREDITACION >= 0)
    );

    PRINT 'Creada RO_T_CASHFLOW_VENTAS_MIX_NODO_MES.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_VENTAS_MIX_NODO_MES ya existia: no se toco.';
GO

SET NOEXEC OFF;
GO

PRINT 'Listo.';
GO
